<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Some gateways send the customer back with a cross-site POST instead of a link. That request
 * carries no session cookie (SameSite=Lax) and no CSRF token, so the real GET return route — which
 * needs the customer's session for the portal and to flash the result message — would never see
 * it. This turns the POST into a same-URL GET (303), with the posted fields added to the query.
 */
class PostedReturnController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $query = http_build_query([...$request->query(), ...$request->post()]);

        return redirect()->to($request->url().($query !== '' ? '?'.$query : ''), 303);
    }
}
