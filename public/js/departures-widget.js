/*
 * Group departures widget for an agency's own marketing site (WordPress, Webflow, plain HTML…).
 *
 *   <div data-crm-departures data-package="EBC-14" data-limit="10" data-pax="2"></div>
 *   <script src="https://your-agency.example.com/js/departures-widget.js" async></script>
 *
 * All attributes are optional: data-package narrows to one package (its package code), data-limit caps the
 * rows (max 50), data-pax pre-fills the travellers on the booking page, data-accent sets the button
 * colour. Data comes from /widget/departures on the same host as this script; "Book" opens the
 * agency's booking page. Rendered in a shadow root so the host page's CSS and ours don't collide.
 */
(() => {
    const script = document.currentScript;
    if (!script || !script.src) return;

    const origin = new URL(script.src).origin;

    const css = `
        :host { all: initial; display: block; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #0f172a; }
        .list { display: grid; gap: 10px; }
        .row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .main { flex: 1 1 220px; min-width: 0; }
        .name { font-weight: 600; margin: 0 0 2px; }
        .dates, .muted { color: #64748b; font-size: 14px; }
        .price { font-weight: 600; white-space: nowrap; }
        .seats { font-size: 13px; white-space: nowrap; }
        .seats.low { color: #b45309; }
        .btn { display: inline-block; padding: 9px 16px; border-radius: 8px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 600; font-size: 14px; white-space: nowrap; }
        .btn.full { background: #94a3b8; }
        .empty { padding: 14px 16px; border: 1px dashed #cbd5e1; border-radius: 10px; color: #64748b; }
    `;

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    const formatDate = (value, withYear) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, {
        day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}),
    });

    const bookUrl = (departure, pax) => {
        const url = new URL(departure.book_url);
        if (pax) url.searchParams.set('pax', pax);
        return url.toString();
    };

    const render = (container, departures) => {
        const root = container.shadowRoot || container.attachShadow({ mode: 'open' });
        const accent = /^#[0-9a-fA-F]{3,8}$/.test(container.dataset.accent || '') ? container.dataset.accent : '#0f766e';
        const style = element('style');
        style.textContent = css;
        const list = element('div', 'list');
        list.style.setProperty('--accent', accent);

        if (departures.length === 0) {
            list.append(element('div', 'empty', 'No upcoming group departures right now — please check back soon.'));
        }

        const pax = /^\d+$/.test(container.dataset.pax || '') ? container.dataset.pax : null;

        for (const departure of departures) {
            const row = element('div', 'row');
            const main = element('div', 'main');
            main.append(
                element('p', 'name', departure.package_name),
                element('div', 'dates', `${formatDate(departure.start_date, false)} – ${formatDate(departure.end_date, true)}`),
            );

            const seats = departure.seats_remaining;
            const link = element('a', departure.is_full ? 'btn full' : 'btn', departure.is_full ? 'Full' : 'Book now');
            link.href = bookUrl(departure, pax);
            link.target = '_top';
            link.rel = 'noopener';

            row.append(
                main,
                element('div', 'price', departure.price_formatted),
                element('div', seats > 0 && seats <= 3 ? 'seats low' : 'seats muted', seats > 0 ? `${seats} seat${seats === 1 ? '' : 's'} left` : 'Waitlist'),
                link,
            );
            list.append(row);
        }

        root.replaceChildren(style, list);
    };

    const load = (container) => {
        const url = new URL('/widget/departures', origin);
        if (container.dataset.package) url.searchParams.set('package', container.dataset.package);
        if (container.dataset.limit) url.searchParams.set('limit', container.dataset.limit);

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'omit' })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload) => render(container, payload.data || []))
            .catch(() => render(container, []));
    };

    const start = () => document.querySelectorAll('[data-crm-departures]').forEach(load);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
