@php
    use App\Http\Controllers\PublicSite\GiftVoucherController;
    use App\Support\TenantContext;

    $tenant = app(TenantContext::class)->get();
    $currency = $tenant->currency ?? 'USD';
    $presets = GiftVoucherController::PRESET_AMOUNTS;
    $amount = old('amount', 100);
    $recipients = old('recipients', [['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '']]);
    $fields = ['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'phone' => 'Mobile'];
@endphp
<x-public-site.layout title="Gift vouchers">
    <h1>Give the gift of travel</h1>
    <p class="lead">Buy a {{ $tenant->name }} gift voucher. Each recipient gets their own voucher code by email as soon as payment is complete.</p>

    <form method="POST" action="{{ route('public.gift-vouchers.store') }}" id="gift-form" class="two-col" novalidate>
        @csrf
        <div class="stack">
            @if ($errors->any())
                <div class="alert">
                    Please check the form.
                    @error('recipients') <br>{{ $message }} @enderror
                </div>
            @endif

            <div class="card">
                <h2>1. Choose an amount</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                    @foreach ($presets as $preset)
                        <button type="button" class="btn ghost preset" data-amount="{{ $preset }}">{{ $currency }} {{ $preset }}</button>
                    @endforeach
                </div>
                <label for="amount">Amount per voucher ({{ $currency }})</label>
                <input type="number" id="amount" name="amount" value="{{ $amount }}" min="{{ GiftVoucherController::MIN_AMOUNT }}" max="{{ GiftVoucherController::MAX_AMOUNT }}" step="1" required>
                <div class="muted">Any amount from {{ GiftVoucherController::MIN_AMOUNT }} to {{ number_format(GiftVoucherController::MAX_AMOUNT) }}.</div>
                @error('amount') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="card">
                <h2>2. Your details</h2>
                <div class="row2">
                    @foreach ($fields as $key => $label)
                        <div class="field">
                            <label for="purchaser_{{ $key }}">{{ $label }}</label>
                            <input type="{{ $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') }}" id="purchaser_{{ $key }}" name="purchaser[{{ $key }}]" value="{{ old("purchaser.{$key}") }}" required>
                            @error("purchaser.{$key}") <div class="error">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <h2>3. Who is it for?</h2>
                <label class="check" style="border: 0;">
                    <input type="checkbox" name="for_myself" value="1" id="for_myself" @checked(old('for_myself'))>
                    <span class="grow">It's for me — send the voucher to my email</span>
                </label>

                <div id="recipients">
                    @foreach ($recipients as $index => $recipient)
                        <div class="recipient" style="border-top: 1px solid var(--line); padding-top: 12px; margin-top: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong class="recipient-title">Recipient {{ $index + 1 }}</strong>
                                <button type="button" class="btn ghost remove" style="padding: 4px 10px; font-size: 13px;">Remove</button>
                            </div>
                            <div class="row2" style="margin-top: 8px;">
                                @foreach ($fields as $key => $label)
                                    <div class="field">
                                        <label>{{ $label }}</label>
                                        <input type="{{ $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') }}" name="recipients[{{ $index }}][{{ $key }}]" value="{{ $recipient[$key] ?? '' }}">
                                        @error("recipients.{$index}.{$key}") <div class="error">{{ $message }}</div> @enderror
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn ghost" id="add-recipient" style="margin-top: 8px;">+ Add another recipient</button>
                <div class="muted" style="margin-top: 6px;">Up to {{ GiftVoucherController::MAX_RECIPIENTS }} recipients, each with their own email. Every recipient gets a voucher for the amount above.</div>
            </div>
        </div>

        <div class="sticky">
            <div class="card">
                <h2>Order summary</h2>
                <table class="summary">
                    <tr><td>Voucher amount</td><td style="text-align: right;" id="sum-amount"></td></tr>
                    <tr><td>Vouchers</td><td style="text-align: right;" id="sum-count"></td></tr>
                    <tr class="total"><td>Total to pay</td><td style="text-align: right;" id="sum-total"></td></tr>
                </table>
                <label class="check" style="border: 0; margin-top: 10px;">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                    @php($termsUrl = \App\Support\PublicSite::termsUrl(app(\App\Support\TenantContext::class)->get()))
                    <span class="grow">I agree to the gift voucher @if ($termsUrl)<a href="{{ $termsUrl }}" target="_blank" rel="noopener">terms and conditions</a>@else terms @endif.</span>
                </label>
                @error('terms') <div class="error">Please accept the terms.</div> @enderror
                <button type="submit" class="btn block" style="margin-top: 12px;">Continue to secure payment</button>
                <p class="muted" style="margin-bottom: 0;">No account needed. You'll pay on the next page.</p>
            </div>
        </div>
    </form>

    <script>
        (() => {
            const form = document.getElementById('gift-form');
            const list = document.getElementById('recipients');
            const max = {{ GiftVoucherController::MAX_RECIPIENTS }};
            const fmt = new Intl.NumberFormat(undefined, { style: 'currency', currency: @json($currency) });

            const renumber = () => {
                [...list.querySelectorAll('.recipient')].forEach((row, index) => {
                    row.querySelector('.recipient-title').textContent = `Recipient ${index + 1}`;
                    row.querySelectorAll('input').forEach((input) => {
                        input.name = input.name.replace(/recipients\[\d+\]/, `recipients[${index}]`);
                    });
                    row.querySelector('.remove').style.visibility = list.children.length > 1 ? 'visible' : 'hidden';
                });
                document.getElementById('add-recipient').style.display = list.children.length >= max ? 'none' : '';
            };

            const summary = () => {
                const forMe = form.for_myself.checked;
                list.parentElement.querySelectorAll('#recipients, #add-recipient').forEach((el) => el.style.display = forMe ? 'none' : '');
                if (!forMe) renumber();
                const amount = Math.max(0, parseFloat(form.amount.value || '0'));
                const count = forMe ? 1 : list.children.length;
                document.getElementById('sum-amount').textContent = fmt.format(amount);
                document.getElementById('sum-count').textContent = count;
                document.getElementById('sum-total').textContent = fmt.format(amount * count);
            };

            document.getElementById('add-recipient').addEventListener('click', () => {
                const row = list.firstElementChild.cloneNode(true);
                row.querySelectorAll('input').forEach((input) => { input.value = ''; });
                row.querySelectorAll('.error').forEach((error) => error.remove());
                list.appendChild(row);
                summary();
            });
            list.addEventListener('click', (event) => {
                if (event.target.classList.contains('remove') && list.children.length > 1) {
                    event.target.closest('.recipient').remove();
                    summary();
                }
            });
            form.querySelectorAll('.preset').forEach((button) => button.addEventListener('click', () => {
                form.amount.value = button.dataset.amount;
                summary();
            }));
            form.addEventListener('input', summary);
            form.addEventListener('change', summary);
            summary();
        })();
    </script>
</x-public-site.layout>
