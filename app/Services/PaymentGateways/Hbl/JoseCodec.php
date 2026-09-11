<?php

namespace App\Services\PaymentGateways\Hbl;

use Jose\Component\Checker\AlgorithmChecker as HeaderAlgorithmChecker;
use Jose\Component\Checker\AudienceChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Checker\NotBeforeChecker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\JWELoader;
use Jose\Component\Encryption\JWETokenSupport;
use Jose\Component\Encryption\Serializer\CompactSerializer as JWECompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSLoader;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer as JWSCompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use JsonException;
use Symfony\Component\Clock\NativeClock;

/**
 * Signs (JWS/PS256) then encrypts (JWE/RSA-OAEP+A128CBC-HS256) a payload for PACO's Core Payment
 * API, and reverses that for a PACO response/webhook — the exact scheme HBL's own PHP demo
 * (web-token/jwt-framework) uses. Built fresh per call rather than cached, since credentials are
 * per-tenant here and must never leak across tenants via a stale builder/key instance.
 */
class JoseCodec
{
    private const JWS_ALGORITHM = 'PS256';

    private const JWE_ALGORITHM = 'RSA-OAEP';

    private const JWE_ENCRYPTION_ALGORITHM = 'A128CBC-HS256';

    private const TOKEN_TYPE = 'JWT';

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function encode(array $payload, string $signingPrivateKeyPem, string $encryptingPublicKeyPem, string $encryptionKeyId): string
    {
        $jwsBuilder = new JWSBuilder(new AlgorithmManager([new PS256]));

        $jws = $jwsBuilder->create()
            ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
            ->addSignature($this->privateKey($signingPrivateKeyPem), [
                'alg' => self::JWS_ALGORITHM,
                'typ' => self::TOKEN_TYPE,
            ])
            ->build();

        $jweBuilder = new JWEBuilder(new AlgorithmManager([new RSAOAEP, new A128CBCHS256]));

        $jwe = $jweBuilder->create()
            ->withPayload((new JWSCompactSerializer)->serialize($jws))
            ->withSharedProtectedHeader([
                'alg' => self::JWE_ALGORITHM,
                'enc' => self::JWE_ENCRYPTION_ALGORITHM,
                'kid' => $encryptionKeyId,
                'typ' => self::TOKEN_TYPE,
            ])
            ->addRecipient($this->publicKey($encryptingPublicKeyPem))
            ->build();

        return (new JWECompactSerializer)->serialize($jwe, 0);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function decode(string $token, string $decryptingPrivateKeyPem, string $verifyingPublicKeyPem, string $expectedAudience): array
    {
        $jweLoader = new JWELoader(
            new JWESerializerManager([new JWECompactSerializer]),
            new JWEDecrypter(new AlgorithmManager([new RSAOAEP, new A128CBCHS256])),
            new HeaderCheckerManager(
                [new HeaderAlgorithmChecker([self::JWE_ALGORITHM], true)],
                [new JWETokenSupport],
            ),
        );

        $recipient = null;
        $jwe = $jweLoader->loadAndDecryptWithKey($token, $this->privateKey($decryptingPrivateKeyPem), $recipient);

        $jwsLoader = new JWSLoader(
            new JWSSerializerManager([new JWSCompactSerializer]),
            new JWSVerifier(new AlgorithmManager([new PS256])),
            new HeaderCheckerManager(
                [new HeaderAlgorithmChecker([self::JWS_ALGORITHM], true)],
                [new JWSTokenSupport],
            ),
        );

        $signature = null;
        $jws = $jwsLoader->loadAndVerifyWithKey($jwe->getPayload(), $this->publicKey($verifyingPublicKeyPem), $signature);

        $claims = json_decode($jws->getPayload(), true, flags: JSON_THROW_ON_ERROR);

        $clock = new NativeClock;

        (new ClaimCheckerManager([
            new NotBeforeChecker($clock),
            new ExpirationTimeChecker($clock),
            new AudienceChecker($expectedAudience),
            new IssuerChecker(['PacoIssuer']),
        ]))->check($claims);

        return $claims;
    }

    private function privateKey(string $key): JWK
    {
        return JWKFactory::createFromKey($this->normalizePem($key, 'RSA PRIVATE KEY'));
    }

    private function publicKey(string $key): JWK
    {
        return JWKFactory::createFromKey($this->normalizePem($key, 'PUBLIC KEY'));
    }

    private function normalizePem(string $key, string $label): string
    {
        $key = trim($key);

        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN {$label}-----\n{$key}\n-----END {$label}-----";
    }
}
