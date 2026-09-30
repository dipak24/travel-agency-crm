{{--
    The whole booking page body, shared by "book on your own dates" and the group-joining page:
    trip details and personal information on the left, a sticky booking summary on the right with
    the live price, promo code, gift voucher and the Book Now button.
    Expects: $action, $package, $departure (nullable), $depositPercent, $maxPax, $minPax, $currency,
    $prefill (array{start_date: ?string, pax: ?int} from the booking link's query string).
--}}
@php
    use App\Enums\PricingUnit;
    use App\Models\Country;
    use App\Services\PublicBooking;
    use App\Support\Money;
    use App\Support\PublicSite;
    use App\Support\TenantContext;

    $tenant = app(TenantContext::class)->get();
    $estimator = app(PublicBooking::class)->estimatorData($package, $departure);
    $extras = $package->includeExcludeItems->filter(fn ($item) => ! $item->is_included && $item->includeExclude);
    $unitLabel = fn (?string $unit): string => PricingUnit::tryFrom((string) $unit)?->getLabel() ?? '';
    $defaultPax = max($minPax, $prefill['pax'] ?? $minPax);
    $days = $departure ? $departure->start_date->diffInDays($departure->end_date) + 1 : max(1, (int) $package->duration_days);
    $countries = Country::options();
    $termsUrl = PublicSite::termsUrl($tenant);
    $hasCustomisation = $extras->isNotEmpty() || $package->services->isNotEmpty();
@endphp

<form method="POST" action="{{ $action }}" id="booking-form" novalidate>
    @csrf
    <div aria-hidden="true" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden;">
        <label for="website">Leave this empty</label>
        <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
    </div>

    @if ($errors->any())
        <div class="alert">Please check the highlighted fields below.</div>
    @endif

    <div class="two-col">
        <div class="stack">
            <section class="card">
                <h2 class="step"><span>1</span> Trip details</h2>

                <div class="field">
                    <label for="trip">Selected trip</label>
                    <input type="text" id="trip" value="{{ $package->name }} ({{ $days }} days)" readonly>
                </div>

                <div class="row2">
                    @if ($departure)
                        <div class="field">
                            <label>Trip dates</label>
                            <input type="text" value="{{ $departure->start_date->format('D, j M Y') }} – {{ $departure->end_date->format('D, j M Y') }}" readonly>
                            <div class="muted">Group departure</div>
                        </div>
                    @else
                        <div class="field">
                            <label for="start_date">Trip start date <span class="req">*</span></label>
                            <input type="date" id="start_date" name="start_date" value="{{ old('start_date', $prefill['start_date'] ?? null) }}" min="{{ now()->addDay()->toDateString() }}" required>
                            <div class="muted" id="end-date" data-days="{{ $days }}"></div>
                            @error('start_date') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    <div class="field">
                        <label for="arrival_date">Arrival date <span class="muted">(optional)</span></label>
                        <input type="date" id="arrival_date" name="arrival_date" value="{{ old('arrival_date') }}" min="{{ now()->toDateString() }}">
                        <div class="muted">When you land, if different from the start date.</div>
                        @error('arrival_date') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="field" style="max-width: 50%;">
                    <label for="pax_count">Number of pax <span class="req">*</span></label>
                    <input type="number" id="pax_count" name="pax_count" value="{{ old('pax_count', $defaultPax) }}" min="1" max="{{ $maxPax }}" required>
                    <div class="muted">{{ $departure ? "Up to {$maxPax} seats left." : ($minPax > 1 ? "Minimum {$minPax}." : 'Up to '.$maxPax.'.') }}</div>
                    @error('pax_count') <div class="error">{{ $message }}</div> @enderror
                </div>
            </section>

            <section class="card">
                <h2 class="step"><span>2</span> Personal information</h2>

                <div class="row2">
                    <div class="field">
                        <label for="name">Full name <span class="req">*</span></label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
                        @error('name') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="email">Email address <span class="req">*</span></label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                        @error('email') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="phone">Phone number <span class="req">*</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel" placeholder="Mobile / WhatsApp">
                        @error('phone') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="country_id">Country <span class="req">*</span></label>
                        <select id="country_id" name="country_id" required autocomplete="country-name">
                            <option value="">Select your country</option>
                            @foreach ($countries as $id => $country)
                                <option value="{{ $id }}" @selected((string) old('country_id') === (string) $id)>{{ $country }}</option>
                            @endforeach
                        </select>
                        @error('country_id') <div class="error">Please choose your country.</div> @enderror
                    </div>
                    <div class="field">
                        <label for="city">City <span class="muted">(optional)</span></label>
                        <input type="text" id="city" name="city" value="{{ old('city') }}" autocomplete="address-level2">
                    </div>
                    <div class="field">
                        <label for="address">Full address <span class="muted">(optional)</span></label>
                        <input type="text" id="address" name="address" value="{{ old('address') }}" autocomplete="street-address">
                    </div>
                    <div class="field">
                        <label for="emergency_contact_name">Emergency contact name <span class="muted">(optional)</span></label>
                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="{{ old('emergency_contact_name') }}">
                    </div>
                    <div class="field">
                        <label for="emergency_contact_phone">Emergency contact phone <span class="muted">(optional)</span></label>
                        <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" value="{{ old('emergency_contact_phone') }}">
                    </div>
                </div>
                <div class="field">
                    <label for="notes">Special message / requirements <span class="muted">(optional)</span></label>
                    <textarea id="notes" name="notes" placeholder="Dietary needs, flight times, questions…">{{ old('notes') }}</textarea>
                </div>
            </section>

            @if ($hasCustomisation)
                <section class="card">
                    <h2 class="step"><span>3</span> Customise your trip <span class="muted">(optional)</span></h2>

                    @if ($extras->isNotEmpty())
                        <div class="field">
                            <label>Optional extras</label>
                            @foreach ($extras as $item)
                                <label class="check">
                                    <input type="checkbox" name="extras[]" value="{{ $item->include_exclude_id }}" @checked(in_array($item->include_exclude_id, old('extras', [])))>
                                    <span class="grow">{{ $item->includeExclude->title }}</span>
                                    <span class="muted">{{ Money::format($item->unit_price, $currency) }} {{ strtolower($unitLabel($item->includeExclude->pricing_unit?->value)) }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    @if ($package->services->isNotEmpty())
                        <div class="field">
                            <label>Add-on activities &amp; services</label>
                            @foreach ($package->services as $service)
                                <label class="check">
                                    <input type="checkbox" name="addons[]" value="{{ $service->id }}" @checked(in_array($service->id, old('addons', [])))>
                                    <span class="grow">{{ $service->name }}@if ($service->description)<br><span class="muted">{{ $service->description }}</span>@endif</span>
                                    <span class="muted">{{ Money::format($service->pivot->price, $currency) }} {{ strtolower($unitLabel($service->pricing_unit?->value)) }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <aside class="sticky">
            <div class="card summary-card">
                <h2>Booking summary</h2>

                <table class="summary">
                    <tr><td>Price per person</td><td class="num">{{ Money::format($estimator['price'], $currency) }}</td></tr>
                    <tr><td>Travellers</td><td class="num" id="summary-pax"></td></tr>
                    <tbody id="estimate-lines"></tbody>
                    <tr class="total"><td>Total price</td><td class="num" id="estimate-total"></td></tr>
                    <tr class="deposit"><td>{{ $depositPercent < 100 ? "Deposit to confirm ({$depositPercent}%)" : 'Pay now' }}</td><td class="num" id="estimate-deposit"></td></tr>
                    <tr id="voucher-line" hidden><td>Gift voucher credit</td><td class="num saved" id="estimate-voucher"></td></tr>
                    <tr id="due-line" class="total" hidden><td>Due now</td><td class="num" id="estimate-due"></td></tr>
                </table>

                <div class="code-box">
                    <label for="promo_code">Promo code</label>
                    <div class="code-row">
                        <input type="text" id="promo_code" name="promo_code" value="{{ old('promo_code') }}" placeholder="Enter code" autocomplete="off">
                        <button type="button" class="btn ghost" data-apply="promo">Apply</button>
                    </div>
                    <div class="code-msg" id="promo-msg">@error('promo_code'){{ $message }}@enderror</div>
                </div>

                <div class="code-box">
                    <label for="gift_voucher">Gift voucher</label>
                    <div class="code-row">
                        <input type="text" id="gift_voucher" name="gift_voucher" value="{{ old('gift_voucher') }}" placeholder="Voucher code" autocomplete="off">
                        <button type="button" class="btn ghost" data-apply="voucher">Apply</button>
                    </div>
                    <div class="code-msg" id="voucher-msg">@error('gift_voucher'){{ $message }}@enderror</div>
                </div>

                <label class="check" style="border: 0;">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                    <span class="grow">I agree to the @if ($termsUrl)<a href="{{ $termsUrl }}" target="_blank" rel="noopener">terms and conditions</a>@else booking terms @endif and that {{ $tenant->name }} may contact me about this trip.</span>
                </label>
                @error('terms') <div class="error">Please accept the booking terms.</div> @enderror

                <button type="submit" class="btn block" id="booking-submit" style="margin-top: 12px;" disabled>Book now</button>
                <div class="muted" id="booking-hint" style="margin-top: 6px;">Fill in the required fields (<span class="req">*</span>) and accept the terms to book.</div>
                <p class="muted" style="margin-bottom: 0;">
                    @if ($depositPercent < 100)
                        You pay a {{ $depositPercent }}% deposit now; we'll send a secure link for the balance before your trip.
                    @endif
                    The final price is confirmed on the payment page.
                </p>
            </div>
        </aside>
    </div>
</form>

<script>
    (() => {
        const data = @json($estimator);
        const deposit = {{ $depositPercent }};
        const checkUrl = @json(route('public.book.check-code', $package->package_code));
        const form = document.getElementById('booking-form');
        const fmt = new Intl.NumberFormat(undefined, { style: 'currency', currency: @json($currency) });
        const money = (cents) => fmt.format(cents / 100);
        const units = (unit, pax, days) => ({ per_trip: 1, per_day: days, per_person: pax, per_person_per_day: pax * days })[unit] ?? pax;
        const applied = { promo: null, voucher: null };

        const bestTier = (pax) => {
            const matching = data.tiers.filter((tier) => tier.min <= pax && (tier.max === null || tier.max >= pax));
            matching.sort((a, b) => (b.packageId !== null) - (a.packageId !== null) || b.min - a.min);
            return matching[0] ?? null;
        };

        const setText = (id, text) => { document.getElementById(id).textContent = text; };

        const render = () => {
            const pax = Math.max(1, parseInt(form.pax_count.value || '1', 10));
            const lines = [];
            const base = data.price * pax;
            lines.push(['Subtotal', base]);

            let total = base;
            const tier = (data.fixedGroup || pax > 1) ? bestTier(pax) : null;
            if (tier) {
                const off = Math.min(base, tier.type === 'percentage' ? Math.round(base * tier.value / 100) : tier.value * pax);
                if (off > 0) { lines.push(['Group discount saved', -off, true]); total -= off; }
            }

            const picked = (name) => [...form.querySelectorAll(`input[name="${name}[]"]:checked`)].map((input) => parseInt(input.value, 10));
            for (const [name, list] of [['extras', data.extras], ['addons', data.addons]]) {
                for (const id of picked(name)) {
                    const item = list.find((entry) => entry.id === id);
                    if (!item) continue;
                    const amount = item.price * units(item.unit, pax, data.days);
                    const label = form.querySelector(`input[name="${name}[]"][value="${id}"]`).closest('label').querySelector('.grow').firstChild.textContent.trim();
                    lines.push([label, amount]);
                    total += amount;
                }
            }

            total = Math.max(0, total);
            if (applied.promo) {
                const off = Math.min(total, applied.promo.type === 'percent' ? Math.round(total * applied.promo.value / 100) : applied.promo.value);
                if (off > 0) { lines.push([`Promo code ${applied.promo.code}`, -off, true]); total -= off; }
            }

            document.getElementById('estimate-lines').innerHTML = lines
                .map(([label, amount, saved]) => `<tr><td>${label.replace(/</g, '&lt;')}</td><td class="num${saved ? ' saved' : ''}">${amount < 0 ? '− ' : ''}${money(Math.abs(amount))}</td></tr>`)
                .join('');
            setText('summary-pax', pax);
            setText('estimate-total', money(total));

            const payNow = deposit >= 100 ? total : Math.round(total * deposit / 100);
            setText('estimate-deposit', money(payNow));

            const credit = applied.voucher ? Math.min(applied.voucher.balance, payNow) : 0;
            document.getElementById('voucher-line').hidden = credit <= 0;
            document.getElementById('due-line').hidden = credit <= 0;
            setText('estimate-voucher', `− ${money(credit)}`);
            setText('estimate-due', money(payNow - credit));
        };

        const showMessage = (kind, text, ok) => {
            const box = document.getElementById(`${kind}-msg`);
            box.textContent = text;
            box.className = `code-msg ${ok ? 'ok' : 'bad'}`;
        };

        const applyCode = async (kind) => {
            const input = form[kind === 'promo' ? 'promo_code' : 'gift_voucher'];
            const code = input.value.trim();
            applied[kind] = null;
            render();
            if (code === '') { showMessage(kind, '', true); return; }

            try {
                const response = await fetch(checkUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form._token.value },
                    body: JSON.stringify({ kind, code, email: form.email.value || null }),
                });
                if (response.status === 429) { showMessage(kind, 'Too many attempts. Please wait a minute and try again.', false); return; }
                const result = await response.json();
                if (!response.ok || !result.valid) { showMessage(kind, result.message ?? 'That code could not be applied.', false); return; }
                applied[kind] = kind === 'promo'
                    ? { code: code.toUpperCase(), type: result.type, value: result.value }
                    : { balance: result.balance };
                showMessage(kind, kind === 'voucher' ? `${result.message} Balance ${money(result.balance)}.` : result.message, true);
                render();
            } catch (error) {
                showMessage(kind, 'Could not check the code. It will be checked again when you book.', false);
            }
        };

        form.querySelectorAll('[data-apply]').forEach((button) => button.addEventListener('click', () => applyCode(button.dataset.apply)));
        for (const [name, kind] of [['promo_code', 'promo'], ['gift_voucher', 'voucher']]) {
            form[name].addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); applyCode(kind); } });
            form[name].addEventListener('input', () => { if (applied[kind]) { applied[kind] = null; showMessage(kind, '', true); render(); } });
        }

        const endDate = document.getElementById('end-date');
        const renderEndDate = () => {
            if (!endDate) return;
            const start = form.start_date.value ? new Date(`${form.start_date.value}T00:00:00`) : null;
            if (!start || Number.isNaN(start.getTime())) { endDate.textContent = ''; return; }
            start.setDate(start.getDate() + parseInt(endDate.dataset.days, 10) - 1);
            endDate.textContent = `Ends ${start.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}`;
        };

        // Book now stays disabled until every required field is filled in and valid.
        const submit = document.getElementById('booking-submit');
        const required = [...form.querySelectorAll('[required]')];
        const refreshSubmit = () => {
            const ready = required.every((field) => field.type === 'checkbox' ? field.checked : field.value.trim() !== '' && field.checkValidity());
            submit.disabled = !ready;
            document.getElementById('booking-hint').hidden = ready;
        };

        form.addEventListener('input', () => { render(); renderEndDate(); refreshSubmit(); });
        form.addEventListener('change', () => { render(); renderEndDate(); refreshSubmit(); });
        form.addEventListener('submit', () => {
            const button = document.getElementById('booking-submit');
            button.disabled = true;
            button.textContent = 'Please wait…';
        });
        // Coming back from the payment page restores this page from the back/forward cache.
        window.addEventListener('pageshow', () => {
            submit.textContent = 'Book now';
            refreshSubmit();
        });

        render();
        renderEndDate();
        refreshSubmit();
        if (form.promo_code.value.trim() !== '' && !document.getElementById('promo-msg').textContent.trim()) applyCode('promo');
        if (form.gift_voucher.value.trim() !== '' && !document.getElementById('voucher-msg').textContent.trim()) applyCode('voucher');
        for (const kind of ['promo', 'voucher']) {
            const box = document.getElementById(`${kind}-msg`);
            if (box.textContent.trim()) box.className = 'code-msg bad';
        }
    })();
</script>
