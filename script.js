// ==================== STATE ====================
let properties = [];
let pendingBooking = null;

// Pencarian katalog berdasarkan tanggal menginap.
const MAX_SEARCH_NIGHTS = 30;
let searchDates = { startDate: '', endDate: '' };
let loadedSearchDates = { startDate: '', endDate: '' };
let catalogRequestSeq = 0;
let searchCalendarMonth = null;
let searchCalendarField = 'start';
let searchCalendarDraft = { startDate: '', endDate: '' };

// Stok & waktu real-time
let serverClock = null;              // { serverMs, perfMs }
let availabilityPollTimer = null;
let availabilityRequestSeq = 0;
let calendarRequestSeq = 0;
let currentPropertyId = null;
let currentCalendarMonth = null;     // { year, month: 0-11 }
let currentCalendarKey = '';
let currentCalendarData = null;
let lastAvailability = null;
let availabilityKey = '';
let availabilityLoading = false;

// Draft pemesanan: tanggal menginap dan rincian tamu
let bookingDraft = null;
let currentBookingRules = null;
let activeStayField = 'start';       // 'start' | 'end'
let stayCalendarOpen = false;
let stayCalendarAvailability = {};   // { 'Y-m-d': { available, blocked, remaining } }
let stayCalendarSeq = 0;
let stayCalendarLoadError = '';

function currentUser() {
    return window.USER_DATA || null;
}

// ==================== UTILITY FUNCTIONS ====================
function formatPrice(price) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(price);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);
}

function showToast(message) {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    toastMessage.textContent = message;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3500);
}

function openModal(el) {
    el.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(el) {
    if (el && el.id === 'propertyModal') {
        closeStayCalendar();
        stopAvailabilityPolling();
        currentPropertyId = null;
        currentCalendarMonth = null;
        currentCalendarData = null;
        currentCalendarKey = '';
    }
    el.classList.remove('active');
    document.body.style.overflow = '';
}

async function apiFetch(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
}

// ==================== WAKTU REAL-TIME (WIB) ====================
function pad2(value) {
    return String(value).padStart(2, '0');
}

// Sinkronkan jam server. Semua perhitungan tanggal memakai jam server,
// bukan jam perangkat user yang bisa saja salah.
function syncServerClock(value) {
    const parsed = typeof value === 'number' ? value : Date.parse(value);
    if (!Number.isFinite(parsed)) return;
    serverClock = {
        serverMs: parsed,
        perfMs: performance.now()
    };
}

function serverNow() {
    if (!serverClock) {
        return new Date();
    }
    return new Date(serverClock.serverMs + (performance.now() - serverClock.perfMs));
}

function appTimeZone() {
    return window.APP_TIMEZONE || 'Asia/Jakarta';
}

function ymdInAppTz(date) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: appTimeZone(),
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).formatToParts(date);

    const values = {};
    parts.forEach(part => {
        if (part.type !== 'literal') values[part.type] = part.value;
    });
    return `${values.year}-${values.month}-${values.day}`;
}

function serverToday() {
    return ymdInAppTz(serverNow());
}

function addDaysYmd(ymd, amount) {
    const [year, month, day] = String(ymd).split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day + amount));
    return `${date.getUTCFullYear()}-${pad2(date.getUTCMonth() + 1)}-${pad2(date.getUTCDate())}`;
}

function formatDateLongId(ymd, withWeekday = true) {
    const date = new Date(`${ymd}T00:00:00+07:00`);
    if (Number.isNaN(date.getTime())) return ymd;

    const options = {
        timeZone: appTimeZone(),
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    };
    if (withWeekday) options.weekday = 'long';

    return new Intl.DateTimeFormat('id-ID', options).format(date);
}

function formatDateShortId(ymd) {
    const date = new Date(`${ymd}T00:00:00+07:00`);
    if (Number.isNaN(date.getTime())) return ymd;

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: appTimeZone(),
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    }).format(date);
}

function monthFromYmd(ymd) {
    const [year, month] = String(ymd).split('-').map(Number);
    return { year, month: month - 1 };
}

function monthKeyFromState(state) {
    return `${state.year}-${pad2(state.month + 1)}`;
}

function shiftMonth(state, amount) {
    const date = new Date(Date.UTC(state.year, state.month + amount, 1));
    return { year: date.getUTCFullYear(), month: date.getUTCMonth() };
}

function updateLiveClock() {
    const el = document.getElementById('liveClock');
    if (!el) return;

    el.textContent = new Intl.DateTimeFormat('id-ID', {
        timeZone: appTimeZone(),
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
    }).format(serverNow()) + ' WIB';
}

// Pasang event listener dengan aman: tidak menghentikan inisialisasi
// listener lain jika elemen tidak ditemukan.
function on(target, event, handler) {
    const el = typeof target === 'string' ? document.getElementById(target) : target;
    if (el) el.addEventListener(event, handler);
    return !!el;
}

function safeInit(fn) {
    try {
        fn();
    } catch (err) {
        console.error('Inisialisasi gagal:', err);
    }
}

// ==================== LOAD PROPERTIES ====================
async function loadProperties(params = {}) {
    const query = new URLSearchParams();
    if (params.startDate && params.endDate) {
        query.set('start_date', params.startDate);
        query.set('end_date', params.endDate);
    }

    const seq = ++catalogRequestSeq;
    const suffix = query.toString() ? `?${query.toString()}` : '';
    const { ok, data } = await apiFetch(`api/properties.php${suffix}`);

    if (seq !== catalogRequestSeq) return false;

    if (!ok) {
        showToast(data.error || 'Katalog tidak dapat dimuat.');
        return false;
    }

    properties = Array.isArray(data.properties) ? data.properties : [];
    loadedSearchDates = params.startDate && params.endDate
        ? { startDate: params.startDate, endDate: params.endDate }
        : { startDate: '', endDate: '' };

    renderProperties();
    return true;
}

// ==================== RENDER PROPERTIES ====================
function formatDateCompactId(ymd) {
    const date = new Date(`${ymd}T00:00:00+07:00`);
    if (Number.isNaN(date.getTime())) return ymd;

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: appTimeZone(),
        day: 'numeric',
        month: 'short'
    }).format(date);
}

function hasActiveSearchDates() {
    return Boolean(searchDates.startDate && searchDates.endDate);
}

function catalogFilterState() {
    const activeCategory = document.querySelector('.category-item.active');
    return {
        category: activeCategory ? activeCategory.dataset.category : 'all',
        searchTerm: (document.getElementById('searchInput')?.value || '').trim().toLowerCase(),
        guestsMin: parseInt(document.getElementById('heroGuests')?.value, 10) || 0,
        bedroomsMin: parseInt(document.getElementById('heroBedrooms')?.value, 10) || 0,
        budget: document.getElementById('heroBudget')?.value || 'all'
    };
}

function matchesBudget(price, budget) {
    if (budget === 'under-250') return price < 250000;
    if (budget === '250-400') return price >= 250000 && price < 400000;
    if (budget === '400-600') return price >= 400000 && price < 600000;
    if (budget === 'over-600') return price >= 600000;
    return true;
}

function renderCatalogMeta(filteredCount) {
    const meta = document.getElementById('catalogMeta');
    if (!meta) return;

    const filters = catalogFilterState();
    const chips = [];

    if (hasActiveSearchDates()) {
        const nights = nightsBetweenYmd(searchDates.startDate, searchDates.endDate);
        chips.push(`
            <button type="button" class="catalog-chip" data-clear-catalog="dates">
                <i class="fas fa-calendar-days"></i>
                ${formatDateCompactId(searchDates.startDate)} &ndash; ${formatDateCompactId(searchDates.endDate)}
                <span>${nights} malam</span>
                <i class="fas fa-xmark"></i>
            </button>
        `);
    }

    if (filters.guestsMin > 0) {
        chips.push(`
            <button type="button" class="catalog-chip" data-clear-catalog="guests">
                <i class="fas fa-users"></i> ${filters.guestsMin}+ tamu
                <i class="fas fa-xmark"></i>
            </button>
        `);
    }

    if (filters.bedroomsMin > 0) {
        chips.push(`
            <button type="button" class="catalog-chip" data-clear-catalog="bedrooms">
                <i class="fas fa-bed"></i> ${filters.bedroomsMin}+ kamar
                <i class="fas fa-xmark"></i>
            </button>
        `);
    }

    if (filters.budget !== 'all') {
        const select = document.getElementById('heroBudget');
        const label = select?.selectedOptions?.[0]?.textContent || 'Anggaran';
        chips.push(`
            <button type="button" class="catalog-chip" data-clear-catalog="budget">
                <i class="fas fa-money-bill-wave"></i> ${label}
                <i class="fas fa-xmark"></i>
            </button>
        `);
    }

    if (filters.searchTerm) {
        chips.push(`
            <button type="button" class="catalog-chip" data-clear-catalog="search">
                <i class="fas fa-magnifying-glass"></i> ${escapeHtml(filters.searchTerm)}
                <i class="fas fa-xmark"></i>
            </button>
        `);
    }

    const total = properties.length;
    const totalLabel = hasActiveSearchDates()
        ? `${total} properti tersedia`
        : `${total} properti`;

    meta.innerHTML = `
        <div class="catalog-count">
            Menampilkan <strong>${filteredCount}</strong> dari ${totalLabel}
        </div>
        ${chips.length > 0 ? `<div class="catalog-chips">${chips.join('')}</div>` : ''}
    `;
}

function renderProperties() {
    const grid = document.getElementById('propertyGrid');
    if (!grid) return;

    const filters = catalogFilterState();
    let filtered = properties;

    if (filters.category !== 'all') {
        const term = filters.category.toLowerCase().replace(/-/g, ' ');
        filtered = filtered.filter(p =>
            p.location.toLowerCase().includes(term) ||
            (p.rules?.label || '').toLowerCase().includes(term)
        );
    }

    if (filters.searchTerm) {
        filtered = filtered.filter(p =>
            p.name.toLowerCase().includes(filters.searchTerm) ||
            p.location.toLowerCase().includes(filters.searchTerm) ||
            p.category.toLowerCase().includes(filters.searchTerm) ||
            (p.rules?.label || '').toLowerCase().includes(filters.searchTerm)
        );
    }

    if (filters.guestsMin > 0) {
        filtered = filtered.filter(p => {
            const capacity = Number(p.rules?.max_guests || p.guests || 0);
            return capacity >= filters.guestsMin;
        });
    }

    if (filters.bedroomsMin > 0) {
        filtered = filtered.filter(p => Number(p.bedrooms || 0) >= filters.bedroomsMin);
    }

    if (filters.budget !== 'all') {
        filtered = filtered.filter(p => matchesBudget(Number(p.price || 0), filters.budget));
    }

    renderCatalogMeta(filtered.length);

    if (filtered.length === 0) {
        const dateEmpty = hasActiveSearchDates();
        grid.innerHTML = `
            <div class="catalog-empty">
                <i class="fas ${dateEmpty ? 'fa-calendar-xmark' : 'fa-search'}"></i>
                <h3>${dateEmpty ? 'Tidak ada properti yang tersedia' : 'Rumah tidak ditemukan'}</h3>
                <p>${dateEmpty
                    ? 'Semua properti pada rentang tanggal ini sedang penuh atau tidak memenuhi aturan menginap. Coba pilih tanggal lain.'
                    : 'Coba ubah filter atau kata kunci pencarian Anda.'}</p>
                ${dateEmpty ? '<button type="button" class="btn-reset-dates" onclick="clearSearchDates(true)"><i class="fas fa-calendar-xmark"></i> Hapus tanggal</button>' : ''}
            </div>
        `;
        return;
    }

    grid.innerHTML = filtered.map(property => `
        <div class="property-card" onclick="openPropertyDetail(${property.id})">
            <div class="property-image">
                <img src="${property.images[0]}" alt="${property.name}" loading="lazy">
                <span class="property-badge">${property.badge || ''}</span>
                <div class="property-dots">
                    <span class="property-dot active"></span>
                    <span class="property-dot"></span>
                    <span class="property-dot"></span>
                </div>
            </div>
            <div class="property-info">
                <div>
                    <h3>${property.name}</h3>
                    <p class="property-location">${property.location}</p>
                </div>
                <div class="price">
                    ${formatPrice(property.price)} <span>/malam</span>
                </div>
            </div>
            <div class="property-rating">
                <i class="fas fa-star"></i> ${property.rating} <span style="color: var(--gray);">(${property.reviews} ulasan)</span>
            </div>
            ${property.stay ? `
                <div class="property-stay">
                    <i class="fas fa-circle-check"></i>
                    Tersedia untuk ${property.stay.nights} malam
                    ${property.stay.remaining === 1 ? '<span>&middot; sisa 1 unit</span>' : ''}
                </div>
            ` : ''}
        </div>
    `).join('');
}

// ==================== PENCARIAN TANGGAL KATALOG ====================
function updateHeroSearchDateUI() {
    const group = document.getElementById('heroDateGroup');
    const value = document.getElementById('heroDateValue');
    const hint = document.getElementById('heroDateHint');
    const clear = document.getElementById('heroDateClear');
    const note = document.getElementById('heroSearchNote');

    if (searchDates.startDate && searchDates.endDate) {
        const nights = nightsBetweenYmd(searchDates.startDate, searchDates.endDate);
        if (value) value.textContent = `${formatDateCompactId(searchDates.startDate)} – ${formatDateCompactId(searchDates.endDate)}`;
        if (hint) hint.textContent = `${nights} malam`;
        if (group) group.classList.add('is-filled');
        if (clear) clear.hidden = false;
        if (note) {
            note.innerHTML = `<i class="fas fa-circle-check"></i> Menampilkan properti yang tersedia untuk ${nights} malam.`;
        }
    } else {
        if (value) value.textContent = 'Tambahkan tanggal';
        if (hint) hint.textContent = 'Check-in – check-out';
        if (group) group.classList.remove('is-filled');
        if (clear) clear.hidden = true;
        if (note) {
            note.innerHTML = '<i class="fas fa-circle-info"></i> Pilih tanggal untuk melihat properti yang masih tersedia.';
        }
    }
}

function sameSearchDates(a, b) {
    return a.startDate === b.startDate && a.endDate === b.endDate;
}

function ensureSearchCalendar() {
    if (document.getElementById('searchCalendarOverlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'searchCalendarOverlay';
    overlay.className = 'stay-calendar-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <div class="stay-calendar-dialog" role="dialog" aria-modal="true" aria-label="Pilih tanggal pencarian">
            <div class="stay-calendar-top">
                <div class="stay-calendar-nights">
                    <strong id="searchCalendarNights">Pilih tanggal</strong>
                    <span id="searchCalendarRange">Tentukan rentang menginap Anda</span>
                </div>
                <div class="stay-calendar-mini">
                    <div class="stay-mini-field" data-search-field="start">
                        <button type="button" class="stay-mini-select" data-search-field="start" aria-label="Pilih check-in">
                            <span>CHECK-IN</span>
                            <strong id="miniSearchCheckin">Pilih tanggal</strong>
                        </button>
                        <button type="button" class="stay-mini-clear" data-search-clear="start" aria-label="Hapus tanggal check-in">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                    <div class="stay-mini-field" data-search-field="end">
                        <button type="button" class="stay-mini-select" data-search-field="end" aria-label="Pilih check-out">
                            <span>CHECK-OUT</span>
                            <strong id="miniSearchCheckout">Pilih tanggal</strong>
                        </button>
                        <button type="button" class="stay-mini-clear" data-search-clear="end" aria-label="Hapus tanggal check-out">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="stay-calendar-months-wrap">
                <button type="button" class="stay-calendar-arrow" id="searchCalendarPrev" aria-label="Bulan sebelumnya">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="stay-calendar-months" id="searchCalendarMonths"></div>
                <button type="button" class="stay-calendar-arrow" id="searchCalendarNext" aria-label="Bulan berikutnya">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <p class="stay-calendar-status" id="searchCalendarStatus" aria-live="polite"></p>

            <div class="stay-calendar-footer">
                <span class="stay-calendar-kbd" aria-hidden="true"><i class="fas fa-umbrella-beach"></i></span>
                <div class="stay-calendar-actions">
                    <button type="button" class="stay-calendar-clear" id="searchCalendarClear">Hapus tanggal</button>
                    <button type="button" class="stay-calendar-clear" id="searchCalendarClose">Tutup</button>
                    <button type="button" class="stay-calendar-close" id="searchCalendarApply" disabled>Terapkan</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeSearchCalendar();
            return;
        }

        const clearButton = event.target.closest('[data-search-clear]');
        if (clearButton) {
            clearSearchCalendarDraft(clearButton.dataset.searchClear);
            return;
        }

        const field = event.target.closest('[data-search-field]');
        if (field) {
            searchCalendarField = field.dataset.searchField;
            renderSearchCalendar();
        }
    });

    const monthsEl = document.getElementById('searchCalendarMonths');
    if (monthsEl) {
        monthsEl.addEventListener('click', (event) => {
            const day = event.target.closest('button[data-date]');
            if (!day || day.disabled) return;
            pickSearchDate(day.dataset.date);
        });
    }

    on('searchCalendarPrev', 'click', () => {
        searchCalendarMonth = shiftMonth(searchCalendarMonth || monthFromYmd(serverToday()), -1);
        renderSearchCalendar();
    });

    on('searchCalendarNext', 'click', () => {
        searchCalendarMonth = shiftMonth(searchCalendarMonth || monthFromYmd(serverToday()), 1);
        renderSearchCalendar();
    });

    on('searchCalendarApply', 'click', applySearchCalendar);
    on('searchCalendarClear', 'click', () => clearSearchCalendarDraft('all'));
    on('searchCalendarClose', 'click', closeSearchCalendar);
}

function searchVisibleMonths() {
    if (!searchCalendarMonth) {
        searchCalendarMonth = monthFromYmd(serverToday());
    }

    const months = [];
    const count = window.matchMedia('(max-width: 700px)').matches ? 1 : 2;
    for (let i = 0; i < count; i++) {
        months.push(shiftMonth(searchCalendarMonth, i));
    }
    return months;
}

function renderSearchCalendar() {
    ensureSearchCalendar();

    const start = searchCalendarDraft.startDate;
    const end = searchCalendarDraft.endDate;
    const nights = start && end ? nightsBetweenYmd(start, end) : 0;

    const nightsEl = document.getElementById('searchCalendarNights');
    const rangeEl = document.getElementById('searchCalendarRange');
    const miniCheckin = document.getElementById('miniSearchCheckin');
    const miniCheckout = document.getElementById('miniSearchCheckout');
    const monthsEl = document.getElementById('searchCalendarMonths');
    const prevBtn = document.getElementById('searchCalendarPrev');
    const applyBtn = document.getElementById('searchCalendarApply');
    const statusEl = document.getElementById('searchCalendarStatus');

    if (nightsEl) nightsEl.textContent = nights > 0 ? `${nights} malam` : 'Pilih tanggal';
    if (rangeEl) {
        rangeEl.textContent = start && end
            ? `${formatDateShortId(start)} - ${formatDateShortId(end)}`
            : 'Tentukan rentang menginap Anda';
    }

    if (miniCheckin) {
        miniCheckin.textContent = start ? formatDateDmy(start) : 'Pilih tanggal';
        miniCheckin.classList.toggle('is-empty', !start);
    }
    if (miniCheckout) {
        miniCheckout.textContent = end ? formatDateDmy(end) : 'Pilih tanggal';
        miniCheckout.classList.toggle('is-empty', !end);
    }

    document.querySelectorAll('#searchCalendarOverlay .stay-mini-field').forEach(field => {
        field.classList.toggle('is-active', field.dataset.searchField === searchCalendarField);
    });

    if (monthsEl) {
        monthsEl.innerHTML = searchVisibleMonths().map(searchMonthHtml).join('');
    }

    if (prevBtn && searchCalendarMonth) {
        const todayMonth = monthFromYmd(serverToday());
        prevBtn.disabled = searchCalendarMonth.year < todayMonth.year ||
            (searchCalendarMonth.year === todayMonth.year && searchCalendarMonth.month <= todayMonth.month);
    }

    document.querySelectorAll('#searchCalendarOverlay [data-search-clear]').forEach(button => {
        const side = button.dataset.searchClear;
        button.disabled = side === 'start' ? !start : !end;
    });

    if (applyBtn) applyBtn.disabled = !(start && end);

    if (statusEl) {
        statusEl.className = 'stay-calendar-status';
        if (start && end) {
            statusEl.innerHTML = `<i class="fas fa-circle-check"></i> ${nights} malam dipilih. Klik Terapkan untuk mencari properti.`;
        } else if (start) {
            statusEl.textContent = 'Pilih tanggal check-out.';
        } else {
            statusEl.textContent = 'Pilih tanggal check-in terlebih dahulu.';
        }
    }
}

function openSearchCalendar() {
    ensureSearchCalendar();

    searchCalendarDraft = { ...searchDates };
    searchCalendarField = searchCalendarDraft.startDate ? 'end' : 'start';
    searchCalendarMonth = monthFromYmd(searchCalendarDraft.startDate || serverToday());

    const overlay = document.getElementById('searchCalendarOverlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
    }

    renderSearchCalendar();
}

function closeSearchCalendar() {
    const overlay = document.getElementById('searchCalendarOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
    }
}

function pickSearchDate(ymd) {
    if (!ymd || ymd < serverToday()) return;

    const draft = searchCalendarDraft;
    if (searchCalendarField === 'end' && draft.startDate && ymd > draft.startDate) {
        const nights = nightsBetweenYmd(draft.startDate, ymd);
        if (nights > MAX_SEARCH_NIGHTS) {
            const statusEl = document.getElementById('searchCalendarStatus');
            if (statusEl) {
                statusEl.className = 'stay-calendar-status is-error';
                statusEl.innerHTML = `<i class="fas fa-circle-exclamation"></i> Maksimal pencarian ${MAX_SEARCH_NIGHTS} malam.`;
            }
            return;
        }
        draft.endDate = ymd;
    } else {
        draft.startDate = ymd;
        draft.endDate = '';
        searchCalendarField = 'end';
    }

    const [selectedYear, selectedMonth] = ymd.split('-').map(Number);
    const isVisible = searchVisibleMonths().some(month =>
        month.year === selectedYear && month.month === selectedMonth - 1
    );
    if (!isVisible) {
        searchCalendarMonth = { year: selectedYear, month: selectedMonth - 1 };
    }

    renderSearchCalendar();
}

function clearSearchCalendarDraft(side = 'all') {
    if (side === 'start' || side === 'all') {
        searchCalendarDraft.startDate = '';
        searchCalendarDraft.endDate = '';
        searchCalendarField = 'start';
    } else {
        searchCalendarDraft.endDate = '';
        searchCalendarField = 'end';
    }
    renderSearchCalendar();
}

async function applySearchCalendar() {
    if (!searchCalendarDraft.startDate || !searchCalendarDraft.endDate) return;

    const nextDates = { ...searchCalendarDraft };
    closeSearchCalendar();
    searchDates = nextDates;
    updateHeroSearchDateUI();

    const ok = await loadProperties(searchDates);
    if (ok) {
        document.getElementById('properties')?.scrollIntoView({ behavior: 'smooth' });
    } else {
        searchDates = loadedSearchDates.startDate && loadedSearchDates.endDate
            ? { ...loadedSearchDates }
            : { startDate: '', endDate: '' };
        updateHeroSearchDateUI();
    }
}

async function refreshCatalogForSearch() {
    if (hasActiveSearchDates()) {
        if (!sameSearchDates(searchDates, loadedSearchDates)) {
            return loadProperties(searchDates);
        }
        renderProperties();
        return true;
    }

    if (loadedSearchDates.startDate && loadedSearchDates.endDate) {
        return loadProperties({});
    }

    renderProperties();
    return true;
}

async function clearSearchDates(shouldScroll = false) {
    searchDates = { startDate: '', endDate: '' };
    searchCalendarDraft = { startDate: '', endDate: '' };
    updateHeroSearchDateUI();

    if (loadedSearchDates.startDate && loadedSearchDates.endDate) {
        await loadProperties({});
    } else {
        renderProperties();
    }

    if (shouldScroll) {
        document.getElementById('properties')?.scrollIntoView({ behavior: 'smooth' });
    }
}

function clearCatalogFilter(type) {
    if (type === 'dates') {
        clearSearchDates(true);
        return;
    }

    if (type === 'guests') {
        const select = document.getElementById('heroGuests');
        if (select) select.value = '0';
    } else if (type === 'bedrooms') {
        const select = document.getElementById('heroBedrooms');
        if (select) select.value = '0';
    } else if (type === 'budget') {
        const select = document.getElementById('heroBudget');
        if (select) select.value = 'all';
    } else if (type === 'search') {
        const input = document.getElementById('searchInput');
        if (input) input.value = '';
    }

    renderProperties();
}

// ==================== ATURAN DESTINASI & TAMU ====================
function fallbackBookingRules(property) {
    const capacity = Math.max(1, parseInt(property?.guests, 10) || 1);
    return {
        key: 'indonesia',
        label: 'Indonesia',
        max_guests: capacity,
        max_infants: 2,
        min_nights: 1,
        max_nights: 30,
        pets_allowed: false,
        max_pets: 0,
        checkin_time: '14:00',
        checkout_time: '12:00',
        note: ''
    };
}

function propertyBookingRules(property) {
    return (property && property.rules) ? property.rules : fallbackBookingRules(property);
}

function formatDateDmy(ymd) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(ymd || ''))) return 'Pilih tanggal';
    const [year, month, day] = String(ymd).split('-');
    return `${day}/${month}/${year}`;
}

function nightsBetweenYmd(startYmd, endYmd) {
    if (!startYmd || !endYmd) return 0;
    const start = Date.parse(`${startYmd}T00:00:00Z`);
    const end = Date.parse(`${endYmd}T00:00:00Z`);
    if (!Number.isFinite(start) || !Number.isFinite(end)) return 0;
    return Math.max(0, Math.round((end - start) / 86400000));
}

function guestLimitFor(key) {
    const rules = currentBookingRules || fallbackBookingRules({});
    if (key === 'adults') return { min: 1, max: Math.max(1, rules.max_guests) };
    if (key === 'children') return { min: 0, max: Math.max(0, rules.max_guests - 1) };
    if (key === 'infants') return { min: 0, max: Math.max(0, rules.max_infants) };
    return { min: 0, max: rules.pets_allowed ? Math.max(0, rules.max_pets) : 0 };
}

function guestSummaryText(draft) {
    const total = Number(draft?.adults || 0) + Number(draft?.children || 0);
    let label = `${total} tamu`;
    if (Number(draft?.infants || 0) > 0) label += `, ${draft.infants} bayi`;
    if (Number(draft?.pets || 0) > 0) label += `, ${draft.pets} hewan`;
    return label;
}

function guestPolicyNoteHtml(rules) {
    let html = `Tempat ini mengizinkan jumlah tamu maksimum <strong>${rules.max_guests} orang</strong>, tidak termasuk bayi.`;
    if (rules.pets_allowed) {
        html += ` Jika Anda membawa lebih dari <strong>${rules.max_pets} hewan peliharaan</strong>, harap beri tahu tuan rumah Anda.`;
    } else {
        html += ' Hewan peliharaan tidak diizinkan di destinasi ini.';
    }
    return html;
}

function bookingPolicyHtml(rules) {
    const maxNightsNote = Number(rules.max_nights) < 30
        ? ` &middot; maksimal ${rules.max_nights} malam`
        : '';

    return `
        <div class="booking-policy">
            <div class="booking-policy-head">
                <i class="fas fa-house-chimney"></i> Ketentuan destinasi ${escapeHtml(rules.label)}
            </div>
            <ul>
                <li><i class="fas fa-clock"></i> Check-in ${escapeHtml(rules.checkin_time)} &middot; Check-out ${escapeHtml(rules.checkout_time)}</li>
                <li><i class="fas fa-moon"></i> Minimal ${rules.min_nights} malam${maxNightsNote}</li>
                <li><i class="fas fa-users"></i> Maksimal ${rules.max_guests} tamu, tidak termasuk bayi</li>
                <li><i class="fas fa-baby"></i> Maksimal ${rules.max_infants} bayi</li>
                <li>
                    <i class="fas ${rules.pets_allowed ? 'fa-paw' : 'fa-ban'}"></i>
                    ${rules.pets_allowed
                        ? `Maksimal ${rules.max_pets} hewan peliharaan`
                        : 'Hewan peliharaan tidak diizinkan'}
                </li>
            </ul>
            ${rules.note ? `<p>${escapeHtml(rules.note)}</p>` : ''}
        </div>
    `;
}

function normalizeBookingDraft(draft, rules) {
    if (!draft) return null;

    const maxGuests = Math.max(1, parseInt(rules.max_guests, 10) || 1);
    const maxNights = Math.max(1, parseInt(rules.max_nights, 10) || 30);
    const minNights = Math.min(maxNights, Math.max(1, parseInt(rules.min_nights, 10) || 1));

    draft.adults = Math.min(maxGuests, Math.max(1, parseInt(draft.adults, 10) || 1));
    draft.children = Math.min(Math.max(0, maxGuests - draft.adults), Math.max(0, parseInt(draft.children, 10) || 0));
    draft.infants = Math.min(Math.max(0, parseInt(rules.max_infants, 10) || 0), Math.max(0, parseInt(draft.infants, 10) || 0));
    draft.pets = rules.pets_allowed
        ? Math.min(Math.max(0, parseInt(rules.max_pets, 10) || 0), Math.max(0, parseInt(draft.pets, 10) || 0))
        : 0;

    if (draft.startDate && draft.endDate) {
        let nights = nightsBetweenYmd(draft.startDate, draft.endDate);
        if (nights < 1) {
            nights = minNights;
        }
        nights = Math.min(maxNights, Math.max(minNights, nights));
        draft.endDate = addDaysYmd(draft.startDate, nights);
    }

    return draft;
}

function buildBookingDraft(property, preselected) {
    const rules = propertyBookingRules(property);
    const today = serverToday();
    const maxNights = Math.max(1, parseInt(rules.max_nights, 10) || 30);
    const minNights = Math.min(maxNights, Math.max(1, parseInt(rules.min_nights, 10) || 1));
    const defaultNights = Math.min(maxNights, Math.max(2, minNights));

    let startDate = preselected && preselected.startDate ? String(preselected.startDate) : today;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(startDate) || startDate < today) {
        startDate = today;
    }

    let nights = defaultNights;
    if (preselected) {
        if (preselected.endDate && /^\d{4}-\d{2}-\d{2}$/.test(String(preselected.endDate))) {
            const parsed = nightsBetweenYmd(startDate, String(preselected.endDate));
            if (parsed >= 1) nights = parsed;
        } else if (Number.isFinite(parseInt(preselected.days, 10)) && parseInt(preselected.days, 10) >= 1) {
            nights = parseInt(preselected.days, 10);
        }
    }
    nights = Math.min(maxNights, Math.max(minNights, nights));

    const draft = {
        startDate,
        endDate: addDaysYmd(startDate, nights),
        adults: preselected ? (parseInt(preselected.adults, 10) || 2) : Math.min(2, rules.max_guests),
        children: preselected ? (parseInt(preselected.children, 10) || 0) : 0,
        infants: preselected ? (parseInt(preselected.infants, 10) || 0) : 0,
        pets: preselected ? (parseInt(preselected.pets, 10) || 0) : 0
    };

    return normalizeBookingDraft(draft, rules);
}

function validateBookingDraft() {
    if (!bookingDraft || !currentBookingRules) {
        return 'Data pemesanan belum siap.';
    }

    const rules = currentBookingRules;
    const start = bookingDraft.startDate;
    const end = bookingDraft.endDate;

    if (!start || !end) return 'Pilih tanggal check-in dan check-out.';
    if (start < serverToday()) return 'Tanggal check-in tidak boleh sebelum hari ini.';

    const nights = nightsBetweenYmd(start, end);
    if (nights < 1) return 'Tanggal check-out harus setelah tanggal check-in.';
    if (nights < rules.min_nights) {
        return `Minimal menginap ${rules.min_nights} malam untuk destinasi ${rules.label}.`;
    }
    if (nights > rules.max_nights) {
        return `Maksimal menginap ${rules.max_nights} malam untuk destinasi ${rules.label}.`;
    }

    if (bookingDraft.adults < 1) return 'Minimal 1 tamu dewasa.';
    if (bookingDraft.adults + bookingDraft.children > rules.max_guests) {
        return `Maksimal ${rules.max_guests} tamu dewasa dan anak untuk destinasi ${rules.label}.`;
    }
    if (bookingDraft.infants > rules.max_infants) {
        return `Maksimal ${rules.max_infants} bayi untuk destinasi ${rules.label}.`;
    }
    if (bookingDraft.pets > 0 && !rules.pets_allowed) {
        return `Hewan peliharaan tidak diizinkan di destinasi ${rules.label}.`;
    }
    if (bookingDraft.pets > rules.max_pets) {
        return `Maksimal ${rules.max_pets} hewan peliharaan untuk destinasi ${rules.label}.`;
    }

    return '';
}

// ==================== PROPERTY DETAIL ====================
function openPropertyDetail(id) {
    const property = properties.find(p => p.id === id);
    if (!property) return;

    stopAvailabilityPolling();
    currentPropertyId = id;

    const modal = document.getElementById('propertyModal');
    const detail = document.getElementById('propertyDetail');

    const today = serverToday();
    const searchPreselect = hasActiveSearchDates()
        ? { startDate: searchDates.startDate, endDate: searchDates.endDate }
        : null;
    const preselected = pendingBooking && pendingBooking.id === property.id
        ? pendingBooking
        : searchPreselect;
    const rules = propertyBookingRules(property);

    currentBookingRules = rules;
    bookingDraft = buildBookingDraft(property, preselected);
    currentCalendarMonth = monthFromYmd(bookingDraft.startDate || today);
    activeStayField = 'start';

    detail.innerHTML = `
        <div class="detail-gallery">
            <img src="${property.images[0]}" alt="${property.name}">
            <img src="${property.images[1] || property.images[0]}" alt="${property.name}">
            <img src="${property.images[2] || property.images[0]}" alt="${property.name}">
        </div>
        <div class="detail-content">
            <div>
                <h2 class="detail-title">${property.name}</h2>
                <p class="detail-location">
                    <i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> ${property.location}
                </p>
                <div class="property-rating" style="font-size: 16px; margin-bottom: 16px;">
                    <i class="fas fa-star"></i> ${property.rating} <span style="color: var(--gray);">(${property.reviews} ulasan)</span>
                </div>
                <div class="detail-amenities">
                    <div class="amenity-item"><i class="fas fa-bed"></i> ${property.bedrooms} Kamar Tidur</div>
                    <div class="amenity-item"><i class="fas fa-bath"></i> ${property.bathrooms} Kamar Mandi</div>
                    <div class="amenity-item"><i class="fas fa-users"></i> ${property.guests} Tamu</div>
                    <div class="amenity-item"><i class="fas fa-door-open"></i> ${property.stock || 1} Unit</div>
                </div>
                <div class="detail-description">
                    <p>${property.description}</p>
                </div>
                <div class="detail-features">
                    <h3>Fasilitas</h3>
                    <div class="feature-tags">
                        ${(property.features || []).map(f => `<span class="feature-tag"><i class="fas fa-check" style="margin-right: 6px; color: var(--gold);"></i>${f}</span>`).join('')}
                    </div>
                </div>
            </div>
            <div class="booking-card">
                ${!currentUser() ? '<div class="login-hint" id="loginHint"><i class="fas fa-lock"></i> Masuk terlebih dahulu untuk dapat memesan properti ini.</div>' : ''}
                <div class="booking-price">
                    <span class="price">${formatPrice(property.price)} <span>/ malam</span></span>
                    ${property.weekend_price && property.weekend_price > 0 ? `<small class="booking-price-note">Akhir pekan (Sabtu–Minggu): ${formatPrice(property.weekend_price)} / malam</small>` : ''}
                    ${property.long_stay_min_days && property.long_stay_discount_percent > 0 ? `<small class="booking-price-note">Diskon ${property.long_stay_discount_percent}% untuk ${property.long_stay_min_days} malam atau lebih</small>` : ''}
                </div>
                <div class="booking-rating">
                    <i class="fas fa-star"></i> ${property.rating} &middot; ${property.reviews} ulasan
                </div>

                <div class="booking-clock">
                    <i class="fas fa-clock"></i>
                    <span id="liveClock">Memuat waktu...</span>
                </div>

                <form class="booking-form" onsubmit="handleBooking(event, ${property.id})">
                    <div class="stay-range" id="stayRange">
                        <button type="button" class="stay-field" id="stayStartBtn" aria-label="Pilih tanggal check-in">
                            <span class="stay-field-label">CHECK-IN</span>
                            <strong class="stay-field-value" id="stayStartValue">Pilih tanggal</strong>
                        </button>
                        <button type="button" class="stay-field" id="stayEndBtn" aria-label="Pilih tanggal check-out">
                            <span class="stay-field-label">CHECK-OUT</span>
                            <strong class="stay-field-value" id="stayEndValue">Pilih tanggal</strong>
                        </button>
                    </div>
                    <p class="stay-summary" id="staySummary">Pilih tanggal menginap Anda</p>

                    <div class="guest-picker" id="guestPicker">
                        <button type="button" class="guest-trigger" id="guestTrigger" aria-expanded="false" aria-controls="guestPanel">
                            <span class="guest-trigger-text">
                                <span class="stay-field-label">TAMU</span>
                                <strong class="stay-field-value" id="guestValue">2 tamu</strong>
                            </span>
                            <i class="fas fa-chevron-down" id="guestChevron"></i>
                        </button>
                        <div class="guest-panel" id="guestPanel" hidden>
                            <div class="guest-row">
                                <div class="guest-row-label">
                                    <strong>Dewasa</strong>
                                    <span>Usia 13+</span>
                                </div>
                                <div class="guest-stepper">
                                    <button type="button" class="guest-step" data-guest="adults" data-delta="-1" aria-label="Kurangi tamu dewasa"><i class="fas fa-minus"></i></button>
                                    <span class="guest-count" id="guest-adults">2</span>
                                    <button type="button" class="guest-step" data-guest="adults" data-delta="1" aria-label="Tambah tamu dewasa"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="guest-row">
                                <div class="guest-row-label">
                                    <strong>Anak</strong>
                                    <span>Usia 2–12</span>
                                </div>
                                <div class="guest-stepper">
                                    <button type="button" class="guest-step" data-guest="children" data-delta="-1" aria-label="Kurangi anak"><i class="fas fa-minus"></i></button>
                                    <span class="guest-count" id="guest-children">0</span>
                                    <button type="button" class="guest-step" data-guest="children" data-delta="1" aria-label="Tambah anak"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="guest-row">
                                <div class="guest-row-label">
                                    <strong>Bayi</strong>
                                    <span>Di bawah 2 tahun</span>
                                </div>
                                <div class="guest-stepper">
                                    <button type="button" class="guest-step" data-guest="infants" data-delta="-1" aria-label="Kurangi bayi"><i class="fas fa-minus"></i></button>
                                    <span class="guest-count" id="guest-infants">0</span>
                                    <button type="button" class="guest-step" data-guest="infants" data-delta="1" aria-label="Tambah bayi"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="guest-row">
                                <div class="guest-row-label">
                                    <strong>Hewan peliharaan</strong>
                                    <span class="guest-pet-link">Membawa hewan pemandu?</span>
                                </div>
                                <div class="guest-stepper">
                                    <button type="button" class="guest-step" data-guest="pets" data-delta="-1" aria-label="Kurangi hewan peliharaan"><i class="fas fa-minus"></i></button>
                                    <span class="guest-count" id="guest-pets">0</span>
                                    <button type="button" class="guest-step" data-guest="pets" data-delta="1" aria-label="Tambah hewan peliharaan"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <p class="guest-policy-note" id="guestPolicyNote">${guestPolicyNoteHtml(rules)}</p>
                        </div>
                    </div>
                    <p class="stay-error" id="stayError" role="alert"></p>

                    ${bookingPolicyHtml(rules)}

                    <div class="availability-block" id="availabilityBlock">
                        <div class="availability-head">
                            <span class="availability-badge is-checking" id="availabilityBadge" role="status" aria-live="polite">
                                <i class="fas fa-circle-notch fa-spin"></i> Memeriksa ketersediaan...
                            </span>
                            <button type="button" class="availability-refresh" id="availabilityRefresh" title="Muat ulang ketersediaan">
                                <i class="fas fa-rotate"></i>
                            </button>
                        </div>
                        <p class="availability-summary" id="availabilitySummary" aria-live="polite">Menghitung stok unit untuk tanggal yang dipilih...</p>
                    </div>

                    <div class="booking-breakdown" id="bookingBreakdown">
                        <div id="bookingPriceRows"></div>
                        <div class="booking-total">
                            <span>Total</span>
                            <strong id="bookingTotal">-</strong>
                        </div>
                    </div>

                    <button type="submit" class="btn-book" disabled>${currentUser() ? 'Lanjut ke Pembayaran' : 'Masuk untuk Memesan'}</button>
                    <p class="booking-note">Pembayaran dilakukan setelah pesanan diajukan. Tanggal otomatis dikunci saat pesanan dibuat.</p>
                </form>
            </div>
        </div>
    `;

    openModal(modal);
    updateLiveClock();
    bindStayBooking(property);
}

function renderStayFields() {
    if (!bookingDraft || !currentBookingRules) return;

    const rules = currentBookingRules;
    const startEl = document.getElementById('stayStartValue');
    const endEl = document.getElementById('stayEndValue');
    const summaryEl = document.getElementById('staySummary');
    const guestValueEl = document.getElementById('guestValue');
    const panel = document.getElementById('guestPanel');

    if (startEl) {
        startEl.textContent = bookingDraft.startDate ? formatDateDmy(bookingDraft.startDate) : 'Pilih tanggal';
        startEl.classList.toggle('is-empty', !bookingDraft.startDate);
    }
    if (endEl) {
        endEl.textContent = bookingDraft.endDate ? formatDateDmy(bookingDraft.endDate) : 'Pilih tanggal';
        endEl.classList.toggle('is-empty', !bookingDraft.endDate);
    }

    if (summaryEl) {
        if (bookingDraft.startDate && bookingDraft.endDate) {
            const nights = nightsBetweenYmd(bookingDraft.startDate, bookingDraft.endDate);
            summaryEl.innerHTML = `<strong>${nights} malam</strong> &middot; ${formatDateShortId(bookingDraft.startDate)} &ndash; ${formatDateShortId(bookingDraft.endDate)}`;
        } else {
            summaryEl.textContent = 'Pilih tanggal check-in dan check-out';
        }
    }

    if (guestValueEl) {
        guestValueEl.textContent = guestSummaryText(bookingDraft);
    }

    if (panel) {
        ['adults', 'children', 'infants', 'pets'].forEach(key => {
            const countEl = document.getElementById(`guest-${key}`);
            if (countEl) countEl.textContent = bookingDraft[key];

            const limit = guestLimitFor(key);
            panel.querySelectorAll(`[data-guest="${key}"]`).forEach(btn => {
                const delta = Number(btn.dataset.delta);
                let disabled = delta < 0
                    ? bookingDraft[key] <= limit.min
                    : bookingDraft[key] >= limit.max;

                if (delta > 0 && (key === 'adults' || key === 'children')) {
                    disabled = (bookingDraft.adults + bookingDraft.children) >= rules.max_guests;
                }

                btn.disabled = disabled;
            });
        });

        const guestNote = document.getElementById('guestPolicyNote');
        if (guestNote) guestNote.innerHTML = guestPolicyNoteHtml(rules);
    }

    setStayError(validateBookingDraft());
    syncBookingButton();
}

function setStayError(message) {
    const el = document.getElementById('stayError');
    if (!el) return;
    el.textContent = message || '';
    el.classList.toggle('is-visible', Boolean(message));
}

function bindStayBooking(property) {
    on('stayStartBtn', 'click', () => openStayCalendar('start'));
    on('stayEndBtn', 'click', () => openStayCalendar('end'));

    const trigger = document.getElementById('guestTrigger');
    const panel = document.getElementById('guestPanel');
    const chevron = document.getElementById('guestChevron');

    if (trigger && panel) {
        trigger.addEventListener('click', () => {
            const willOpen = panel.hidden;
            panel.hidden = !willOpen;
            trigger.setAttribute('aria-expanded', String(willOpen));
            trigger.classList.toggle('is-open', willOpen);
            if (chevron) chevron.className = `fas fa-chevron-${willOpen ? 'up' : 'down'}`;

            if (willOpen) {
                requestAnimationFrame(() => {
                    panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                });
            }
        });

        panel.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-guest]');
            if (!button || button.disabled) return;

            const key = button.dataset.guest;
            const delta = Number(button.dataset.delta);
            const limit = guestLimitFor(key);
            const next = bookingDraft[key] + delta;

            if (next < limit.min || next > limit.max) return;
            if (delta > 0 && (key === 'adults' || key === 'children')) {
                if (bookingDraft.adults + bookingDraft.children >= currentBookingRules.max_guests) return;
            }

            bookingDraft[key] = next;
            renderStayFields();
        });
    }

    on('availabilityRefresh', 'click', () => {
        refreshAvailability();
        if (stayCalendarOpen) loadStayCalendarAvailability();
    });

    ensureStayCalendar();
    renderStayFields();
    refreshAvailability();

    availabilityPollTimer = setInterval(() => {
        if (!currentPropertyId) return;
        refreshAvailability();
        if (stayCalendarOpen) loadStayCalendarAvailability();
    }, 30000);
}

// ==================== KETERSEDIAAN / STOK ====================
function stopAvailabilityPolling() {
    if (availabilityPollTimer) {
        clearInterval(availabilityPollTimer);
        availabilityPollTimer = null;
    }
    availabilityRequestSeq++;
    calendarRequestSeq++;
    stayCalendarSeq++;
    lastAvailability = null;
    availabilityKey = '';
    availabilityLoading = false;
    bookingDraft = null;
    currentBookingRules = null;
    stayCalendarOpen = false;
    stayCalendarAvailability = {};
    stayCalendarLoadError = '';
}

function currentAvailabilityKey() {
    if (!currentPropertyId || !bookingDraft || !bookingDraft.startDate || !bookingDraft.endDate) {
        return '';
    }
    return `${currentPropertyId}|${bookingDraft.startDate}|${bookingDraft.endDate}`;
}

function setAvailabilityChecking() {
    const badge = document.getElementById('availabilityBadge');
    const summary = document.getElementById('availabilitySummary');
    const button = document.querySelector('#propertyDetail .btn-book');
    const rows = document.getElementById('bookingPriceRows');
    const total = document.getElementById('bookingTotal');

    if (badge) {
        badge.className = 'availability-badge is-checking';
        badge.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Memeriksa ketersediaan...';
    }
    if (summary) {
        summary.textContent = 'Menghitung stok unit untuk tanggal yang dipilih...';
    }
    if (button) {
        button.disabled = true;
        button.textContent = 'Memeriksa Tanggal...';
    }
    if (rows) rows.innerHTML = '';
    if (total) total.textContent = '-';
}

function setAvailabilityError(message) {
    const badge = document.getElementById('availabilityBadge');
    const summary = document.getElementById('availabilitySummary');
    const button = document.querySelector('#propertyDetail .btn-book');

    if (badge) {
        badge.className = 'availability-badge is-unavailable';
        badge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Gagal memeriksa';
    }
    if (summary) {
        summary.textContent = message || 'Ketersediaan tidak dapat dimuat. Coba muat ulang.';
    }
    if (button) {
        button.disabled = true;
        button.textContent = 'Ketersediaan Tidak Diketahui';
    }
}

function resetAvailabilityUi() {
    const badge = document.getElementById('availabilityBadge');
    const summary = document.getElementById('availabilitySummary');
    const button = document.querySelector('#propertyDetail .btn-book');
    const rows = document.getElementById('bookingPriceRows');
    const total = document.getElementById('bookingTotal');

    if (badge) {
        badge.className = 'availability-badge is-idle';
        badge.innerHTML = '<i class="fas fa-calendar-days"></i> Pilih tanggal';
    }
    if (summary) {
        summary.textContent = 'Pilih tanggal check-in dan check-out untuk melihat ketersediaan.';
    }
    if (button) {
        button.disabled = true;
        button.textContent = 'Pilih Tanggal';
    }
    if (rows) rows.innerHTML = '';
    if (total) total.textContent = '-';
}

function renderAvailability(data) {
    const badge = document.getElementById('availabilityBadge');
    const summary = document.getElementById('availabilitySummary');
    const rows = document.getElementById('bookingPriceRows');
    const totalEl = document.getElementById('bookingTotal');
    const property = properties.find(p => p.id === currentPropertyId);
    const available = Boolean(data.available);
    const start = data.start_date || bookingDraft?.startDate || '';
    const end = data.checkout_date || bookingDraft?.endDate || '';
    const days = Number(data.days) || nightsBetweenYmd(start, end);
    const rangeLabel = `${formatDateShortId(start)} &ndash; ${formatDateShortId(end)}`;

    if (badge) {
        badge.className = 'availability-badge ' + (available ? 'is-available' : 'is-unavailable');
        badge.innerHTML = available
            ? '<i class="fas fa-circle-check"></i> Tersedia'
            : '<i class="fas fa-circle-xmark"></i> Tanggal Penuh';
    }

    if (summary) {
        const blocked = (data.blocked_dates || []).map(formatDateShortId);
        const hasManualBlock = (data.dates || []).some(item => item.blocked);

        let html = `<strong>${days} malam</strong> &middot; ${rangeLabel}`;
        html += `<br>Sisa unit pada rentang ini: <strong>${data.remaining} dari ${data.stock}</strong>`;

        if (!available) {
            const list = blocked.slice(0, 3).join(', ');
            const more = blocked.length > 3 ? ` dan ${blocked.length - 3} tanggal lain` : '';
            const reason = hasManualBlock ? 'Diblokir owner' : 'Penuh';
            html += `<br><span class="availability-blocked"><i class="fas fa-ban"></i> ${reason}: ${list}${more}</span>`;
        } else {
            html += `<br><span class="availability-ok"><i class="fas fa-lock"></i> Tanggal akan dikunci setelah pesanan dibuat.</span>`;
        }
        summary.innerHTML = html;
    }

    if (rows && totalEl) {
        const pricing = data.pricing || null;

        if (pricing) {
            let html = `<div class="booking-row"><span>Biaya sewa (${days} malam)</span><span>${formatPrice(pricing.base_total)}</span></div>`;
            if (pricing.discount_total > 0) {
                html += `<div class="booking-row booking-row-discount"><span>Diskon ${pricing.discount_percent}%</span><span>-${formatPrice(pricing.discount_total)}</span></div>`;
            }
            html += `<div class="booking-row"><span>Biaya layanan (5%)</span><span>${formatPrice(pricing.service_fee)}</span></div>`;
            rows.innerHTML = html;
            totalEl.textContent = formatPrice(pricing.total);
        } else if (property) {
            rows.innerHTML = `<div class="booking-row"><span>Biaya sewa (${days} malam)</span><span>${formatPrice(property.price * days)}</span></div>`;
            totalEl.textContent = formatPrice(property.price * days);
        }
    }

    syncBookingButton();
    updateStayCalendarStatus();
}

function syncBookingButton() {
    const button = document.querySelector('#propertyDetail .btn-book');
    if (!button || availabilityLoading) return;

    const key = currentAvailabilityKey();
    if (!lastAvailability || availabilityKey !== key) return;

    if (!lastAvailability.available) {
        button.disabled = true;
        button.textContent = 'Tanggal Tidak Tersedia';
        return;
    }

    if (validateBookingDraft()) {
        button.disabled = true;
        button.textContent = 'Lengkapi Data Pemesanan';
        return;
    }

    button.disabled = false;
    button.textContent = currentUser() ? 'Lanjut ke Pembayaran' : 'Masuk untuk Memesan';
}

async function refreshAvailability() {
    const propertyId = currentPropertyId;

    if (!propertyId || !bookingDraft || !bookingDraft.startDate || !bookingDraft.endDate) {
        lastAvailability = null;
        availabilityKey = '';
        resetAvailabilityUi();
        updateStayCalendarStatus();
        return;
    }

    const nights = nightsBetweenYmd(bookingDraft.startDate, bookingDraft.endDate);
    if (nights < 1) {
        lastAvailability = null;
        availabilityKey = '';
        resetAvailabilityUi();
        updateStayCalendarStatus();
        return;
    }

    const key = currentAvailabilityKey();
    const seq = ++availabilityRequestSeq;
    availabilityLoading = true;
    setAvailabilityChecking();
    updateStayCalendarStatus();

    const { ok, data } = await apiFetch(
        `api/availability.php?property_id=${encodeURIComponent(propertyId)}` +
        `&start_date=${encodeURIComponent(bookingDraft.startDate)}&days=${encodeURIComponent(nights)}`
    );

    if (seq !== availabilityRequestSeq) return;

    availabilityLoading = false;

    if (!ok) {
        lastAvailability = null;
        availabilityKey = key;
        setAvailabilityError(data.error || 'Gagal memeriksa ketersediaan.');
        updateStayCalendarStatus();
        return;
    }

    if (data.server_time) syncServerClock(data.server_time);
    lastAvailability = data;
    availabilityKey = key;
    renderAvailability(data);
}

async function handleBooking(e, id) {
    e.preventDefault();

    if (!bookingDraft) return;

    const error = validateBookingDraft();
    if (error) {
        showToast(error);
        setStayError(error);
        return;
    }

    const startDate = bookingDraft.startDate;
    const endDate = bookingDraft.endDate;
    const days = nightsBetweenYmd(startDate, endDate);

    if (!lastAvailability || availabilityKey !== currentAvailabilityKey() || !lastAvailability.available) {
        showToast('Tanggal yang dipilih sudah penuh. Silakan pilih tanggal lain.');
        return;
    }

    const guestPayload = {
        adults: bookingDraft.adults,
        children: bookingDraft.children,
        infants: bookingDraft.infants,
        pets: bookingDraft.pets
    };

    if (!currentUser()) {
        pendingBooking = { id, startDate, endDate, days, ...guestPayload };
        closeStayCalendar();
        closeModal(document.getElementById('propertyModal'));
        showToast('Silakan masuk terlebih dahulu untuk memesan');
        openModal(document.getElementById('loginModal'));
        return;
    }

    const form = e.currentTarget;
    const btn = form.querySelector('.btn-book');
    btn.disabled = true;
    btn.textContent = 'Memproses...';

    const { ok, data } = await apiFetch('api/booking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            property_id: id,
            start_date: startDate,
            end_date: endDate,
            days,
            ...guestPayload
        })
    });

    if (ok && data.order_id) {
        pendingBooking = null;
        closeStayCalendar();
        closeModal(document.getElementById('propertyModal'));
        showToast('Pesanan dibuat. Mengarahkan ke halaman pembayaran...');
        window.location.href = data.payment_url || `pembayaran.php?order_id=${data.order_id}`;
        return;
    }

    if (ok) {
        showToast('Pesanan berhasil dibuat.');
        window.location.href = 'index.php';
        return;
    }

    showToast(data.error || 'Gagal mengajukan pesanan');
    await refreshAvailability();
}

// ==================== KALENDER CHECK-IN / CHECK-OUT ====================
function stayVisibleMonthCount() {
    return window.matchMedia('(max-width: 700px)').matches ? 1 : 2;
}

function visibleStayMonths() {
    if (!currentCalendarMonth) {
        currentCalendarMonth = monthFromYmd(bookingDraft?.startDate || serverToday());
    }

    const months = [];
    for (let i = 0; i < stayVisibleMonthCount(); i++) {
        months.push(shiftMonth(currentCalendarMonth, i));
    }
    return months;
}

function ensureStayCalendar() {
    if (document.getElementById('stayCalendarOverlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'stayCalendarOverlay';
    overlay.className = 'stay-calendar-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <div class="stay-calendar-dialog" role="dialog" aria-modal="true" aria-label="Pilih tanggal check-in dan check-out">
            <div class="stay-calendar-top">
                <div class="stay-calendar-nights">
                    <strong id="stayCalendarNights">2 malam</strong>
                    <span id="stayCalendarRange">18 Des 2026 - 20 Des 2026</span>
                </div>
                <div class="stay-calendar-mini">
                    <div class="stay-mini-field" data-mini-field="start">
                        <button type="button" class="stay-mini-select" data-mini-field="start" aria-label="Pilih check-in">
                            <span>CHECK-IN</span>
                            <strong id="miniCheckin">18/12/2026</strong>
                        </button>
                        <button type="button" class="stay-mini-clear" data-clear-side="start" aria-label="Hapus tanggal check-in">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                    <div class="stay-mini-field" data-mini-field="end">
                        <button type="button" class="stay-mini-select" data-mini-field="end" aria-label="Pilih check-out">
                            <span>CHECK-OUT</span>
                            <strong id="miniCheckout">20/12/2026</strong>
                        </button>
                        <button type="button" class="stay-mini-clear" data-clear-side="end" aria-label="Hapus tanggal check-out">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="stay-calendar-months-wrap">
                <button type="button" class="stay-calendar-arrow" id="stayCalendarPrev" aria-label="Dua bulan sebelumnya">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="stay-calendar-months" id="stayCalendarMonths"></div>
                <button type="button" class="stay-calendar-arrow" id="stayCalendarNext" aria-label="Dua bulan berikutnya">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <p class="stay-calendar-status" id="stayCalendarStatus" aria-live="polite"></p>

            <div class="stay-calendar-footer">
                <span class="stay-calendar-kbd" aria-hidden="true"><i class="fas fa-keyboard"></i></span>
                <div class="stay-calendar-actions">
                    <button type="button" class="stay-calendar-clear" id="stayCalendarClear">Hapus tanggal</button>
                    <button type="button" class="stay-calendar-close" id="stayCalendarClose">Tutup</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeStayCalendar();
            return;
        }

        const clearButton = event.target.closest('[data-clear-side]');
        if (clearButton) {
            clearStayDate(clearButton.dataset.clearSide);
            return;
        }

        const miniField = event.target.closest('[data-mini-field]');
        if (miniField) {
            activeStayField = miniField.dataset.miniField;
            renderStayCalendar();
        }
    });

    const monthsEl = document.getElementById('stayCalendarMonths');
    if (monthsEl) {
        monthsEl.addEventListener('click', (event) => {
            const day = event.target.closest('button[data-date]');
            if (!day || day.disabled) return;
            pickStayDate(day.dataset.date);
        });
    }

    on('stayCalendarPrev', 'click', () => {
        currentCalendarMonth = shiftMonth(currentCalendarMonth || monthFromYmd(serverToday()), -1);
        renderStayCalendar();
        loadStayCalendarAvailability();
    });

    on('stayCalendarNext', 'click', () => {
        currentCalendarMonth = shiftMonth(currentCalendarMonth || monthFromYmd(serverToday()), 1);
        renderStayCalendar();
        loadStayCalendarAvailability();
    });

    on('stayCalendarClose', 'click', closeStayCalendar);
    on('stayCalendarClear', 'click', () => clearStayDate('start'));
}

function openStayCalendar(field) {
    if (!bookingDraft) return;

    const guestPanel = document.getElementById('guestPanel');
    const guestTrigger = document.getElementById('guestTrigger');
    const guestChevron = document.getElementById('guestChevron');
    if (guestPanel && !guestPanel.hidden) {
        guestPanel.hidden = true;
        if (guestTrigger) {
            guestTrigger.setAttribute('aria-expanded', 'false');
            guestTrigger.classList.remove('is-open');
        }
        if (guestChevron) guestChevron.className = 'fas fa-chevron-down';
    }

    ensureStayCalendar();
    activeStayField = field === 'end' ? 'end' : 'start';

    const baseDate = bookingDraft.startDate || bookingDraft.endDate || serverToday();
    currentCalendarMonth = monthFromYmd(baseDate);

    stayCalendarOpen = true;
    const overlay = document.getElementById('stayCalendarOverlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
    }

    renderStayCalendar();
    loadStayCalendarAvailability();
}

function closeStayCalendar() {
    const overlay = document.getElementById('stayCalendarOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
    }
    stayCalendarOpen = false;
    stayCalendarSeq++;
}

function pickStayDate(ymd) {
    if (!bookingDraft) return;

    const rules = currentBookingRules;
    const start = bookingDraft.startDate;
    const end = bookingDraft.endDate;

    if (activeStayField === 'end' && start && ymd > start) {
        bookingDraft.endDate = ymd;
    } else {
        bookingDraft.startDate = ymd;
        if (!end || end <= ymd) {
            const defaultNights = Math.min(rules.max_nights, Math.max(2, rules.min_nights));
            bookingDraft.endDate = addDaysYmd(ymd, defaultNights);
        }
        activeStayField = 'end';
    }

    normalizeBookingDraft(bookingDraft, rules);
    renderStayFields();
    renderStayCalendar();
    refreshAvailability();
}

function clearStayDate(side) {
    if (!bookingDraft) return;

    if (side === 'start') {
        bookingDraft.startDate = '';
        bookingDraft.endDate = '';
        activeStayField = 'start';
    } else {
        bookingDraft.endDate = '';
        activeStayField = 'end';
    }

    renderStayFields();
    renderStayCalendar();
    refreshAvailability();
}

function rangeMonthHtml(state, start, end, availability = {}, options = {}) {
    const { year, month } = state;
    const first = new Date(Date.UTC(year, month, 1));
    const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
    const startOffset = first.getUTCDay(); // Minggu = kolom pertama.
    const today = serverToday();
    const showAvailability = Boolean(options.showAvailability);

    const title = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'UTC',
        month: 'long',
        year: 'numeric'
    }).format(first);

    const cells = [];
    for (let i = 0; i < startOffset; i++) {
        cells.push('<span class="stay-day-blank" aria-hidden="true"></span>');
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const ymd = `${year}-${pad2(month + 1)}-${pad2(day)}`;
        const info = availability[ymd];
        const isPast = ymd < today;
        const isBlocked = Boolean(showAvailability && info && info.blocked);
        const isFull = Boolean(showAvailability && info && !info.available);
        const isToday = ymd === today;
        const isStart = ymd === start;
        const isEnd = ymd === end;
        const isInRange = Boolean(start && end && ymd > start && ymd < end);
        const classes = ['stay-day'];

        if (isPast) classes.push('is-past');
        if (isFull) classes.push('is-full');
        if (isBlocked) classes.push('is-blocked');
        if (isToday) classes.push('is-today');
        if (isInRange) classes.push('is-in-range');
        if (isStart) classes.push('is-start', 'is-selected');
        if (isEnd) classes.push('is-end', 'is-selected');

        const statusLabel = !showAvailability
            ? 'pilih tanggal'
            : isBlocked
                ? 'diblokir owner'
                : isFull
                    ? 'penuh'
                    : info
                        ? `sisa ${info.remaining} unit`
                        : 'tersedia';
        const label = `${formatDateLongId(ymd)} - ${statusLabel}`;

        cells.push(`<button type="button" class="${classes.join(' ')}" data-date="${ymd}" ${isPast ? 'disabled' : ''} aria-label="${label}" title="${label}" aria-pressed="${isStart || isEnd}">${day}</button>`);
    }

    return `
        <div class="stay-month">
            <div class="stay-month-title">${title}</div>
            <div class="stay-weekdays">
                <span>Min</span><span>Sn</span><span>Sl</span><span>R</span><span>Km</span><span>J</span><span>Sb</span>
            </div>
            <div class="stay-days">${cells.join('')}</div>
        </div>
    `;
}

function stayMonthHtml(state) {
    return rangeMonthHtml(
        state,
        bookingDraft?.startDate || '',
        bookingDraft?.endDate || '',
        stayCalendarAvailability,
        { showAvailability: true }
    );
}

function searchMonthHtml(state) {
    return rangeMonthHtml(
        state,
        searchCalendarDraft.startDate,
        searchCalendarDraft.endDate,
        {},
        { showAvailability: false }
    );
}

function renderStayCalendar() {
    if (!bookingDraft) return;

    ensureStayCalendar();

    const start = bookingDraft.startDate;
    const end = bookingDraft.endDate;
    const nights = start && end ? nightsBetweenYmd(start, end) : 0;

    const nightsEl = document.getElementById('stayCalendarNights');
    const rangeEl = document.getElementById('stayCalendarRange');
    const miniCheckin = document.getElementById('miniCheckin');
    const miniCheckout = document.getElementById('miniCheckout');
    const monthsEl = document.getElementById('stayCalendarMonths');
    const prevBtn = document.getElementById('stayCalendarPrev');

    if (nightsEl) nightsEl.textContent = nights > 0 ? `${nights} malam` : 'Pilih tanggal';
    if (rangeEl) {
        rangeEl.textContent = start && end
            ? `${formatDateShortId(start)} - ${formatDateShortId(end)}`
            : 'Tentukan tanggal menginap Anda';
    }

    if (miniCheckin) {
        miniCheckin.textContent = start ? formatDateDmy(start) : 'Pilih tanggal';
        miniCheckin.classList.toggle('is-empty', !start);
    }
    if (miniCheckout) {
        miniCheckout.textContent = end ? formatDateDmy(end) : 'Pilih tanggal';
        miniCheckout.classList.toggle('is-empty', !end);
    }

    document.querySelectorAll('.stay-mini-field').forEach(field => {
        field.classList.toggle('is-active', field.dataset.miniField === activeStayField);
    });

    const startBtn = document.getElementById('stayStartBtn');
    const endBtn = document.getElementById('stayEndBtn');
    if (startBtn) startBtn.classList.toggle('is-active', activeStayField === 'start');
    if (endBtn) endBtn.classList.toggle('is-active', activeStayField === 'end');

    if (monthsEl) {
        monthsEl.innerHTML = visibleStayMonths().map(stayMonthHtml).join('');
    }

    if (prevBtn && currentCalendarMonth) {
        const todayMonth = monthFromYmd(serverToday());
        prevBtn.disabled = currentCalendarMonth.year < todayMonth.year ||
            (currentCalendarMonth.year === todayMonth.year && currentCalendarMonth.month <= todayMonth.month);
    }

    document.querySelectorAll('[data-clear-side]').forEach(button => {
        const side = button.dataset.clearSide;
        button.disabled = side === 'start' ? !start : !end;
    });

    updateStayCalendarStatus();
}

function updateStayCalendarStatus() {
    const el = document.getElementById('stayCalendarStatus');
    if (!el) return;

    const error = validateBookingDraft();
    if (error) {
        el.className = 'stay-calendar-status is-error';
        el.innerHTML = `<i class="fas fa-circle-exclamation"></i> ${escapeHtml(error)}`;
        return;
    }

    const key = currentAvailabilityKey();

    if (availabilityLoading) {
        el.className = 'stay-calendar-status';
        el.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Memeriksa ketersediaan...';
        return;
    }

    if (stayCalendarLoadError) {
        el.className = 'stay-calendar-status is-error';
        el.innerHTML = `<i class="fas fa-triangle-exclamation"></i> ${escapeHtml(stayCalendarLoadError)}`;
        return;
    }

    if (lastAvailability && availabilityKey === key) {
        if (lastAvailability.available) {
            el.className = 'stay-calendar-status is-ok';
            el.innerHTML = `<i class="fas fa-circle-check"></i> Tanggal tersedia &middot; sisa ${lastAvailability.remaining} dari ${lastAvailability.stock} unit`;
        } else {
            el.className = 'stay-calendar-status is-error';
            el.innerHTML = '<i class="fas fa-circle-xmark"></i> Tanggal yang dipilih sudah penuh. Silakan pilih tanggal lain.';
        }
        return;
    }

    el.className = 'stay-calendar-status';
    el.textContent = 'Pilih tanggal check-in lalu tanggal check-out.';
}

async function loadStayCalendarAvailability() {
    const propertyId = currentPropertyId;
    if (!propertyId || !stayCalendarOpen) return;

    const months = visibleStayMonths().map(monthKeyFromState);
    const seq = ++stayCalendarSeq;

    stayCalendarLoadError = '';
    document.querySelectorAll('.stay-calendar-status').forEach(el => {
        el.className = 'stay-calendar-status';
        el.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Memuat ketersediaan...';
    });

    const results = await Promise.all(months.map(month => apiFetch(
        `api/availability.php?property_id=${encodeURIComponent(propertyId)}&month=${encodeURIComponent(month)}`
    )));

    if (seq !== stayCalendarSeq || !stayCalendarOpen) return;

    const merged = {};
    let loaded = 0;
    let lastError = '';

    results.forEach(({ ok, data }) => {
        if (ok && data.dates) {
            loaded++;
            (data.dates || []).forEach(item => {
                merged[item.date] = item;
            });
            if (data.server_time) syncServerClock(data.server_time);
        } else {
            lastError = data.error || 'Ketersediaan kalender tidak dapat dimuat.';
        }
    });

    if (loaded > 0) {
        stayCalendarAvailability = merged;
    } else {
        stayCalendarLoadError = lastError || 'Ketersediaan kalender tidak dapat dimuat.';
    }

    renderStayCalendar();
}

// ==================== AUTH ====================
async function handleLogin(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const errEl = document.getElementById('loginError');
    errEl.textContent = '';

    const payload = {
        email: form.email.value,
        password: form.password.value
    };

    let result;
    try {
        result = await apiFetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    } catch (err) {
        errEl.textContent = 'Tidak dapat terhubung ke server. Silakan coba lagi.';
        return;
    }

    const { ok, data } = result;

    if (ok) {
        if (pendingBooking) {
            sessionStorage.setItem('pendingBooking', JSON.stringify(pendingBooking));
            sessionStorage.setItem('loginWelcome', `Selamat datang, ${data.user.full_name}! Lengkapi pemesanan Anda.`);
            pendingBooking = null;
        }
        window.location.reload();
    } else {
        errEl.textContent = data.error || 'Login gagal';
    }
}

async function handleRegister(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const errEl = document.getElementById('registerError');
    errEl.textContent = '';

    const payload = {
        full_name: form.full_name.value,
        email: form.email.value,
        phone: form.phone.value,
        password: form.password.value
    };

    const { ok, data } = await apiFetch('api/register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });

    if (ok) {
        window.location.reload();
    } else {
        errEl.textContent = data.error || 'Pendaftaran gagal';
    }
}

async function handleLogout() {
    await apiFetch('api/logout.php', { method: 'POST' });
    window.location.href = 'index.php';
}

// ==================== MY ORDERS ====================
const orderStatusLabels = {
    pending_payment: 'Menunggu Pembayaran',
    paid: 'Sudah Dibayar',
    confirmed: 'Dikonfirmasi',
    rejected: 'Ditolak',
    expired: 'Kedaluwarsa',
    cancelled: 'Dibatalkan',
    completed: 'Selesai'
};

async function openOrders() {
    openModal(document.getElementById('ordersModal'));
    const container = document.getElementById('ordersList');
    container.innerHTML = '<p style="text-align:center; color: var(--gray);">Memuat pesanan...</p>';

    const { ok, data } = await apiFetch('api/orders.php');
    if (!ok) {
        container.innerHTML = `<p style="text-align:center; color: var(--danger);">${data.error || 'Gagal memuat pesanan'}</p>`;
        return;
    }

    const orders = data.orders || [];
    if (orders.length === 0) {
        container.innerHTML = `
            <div style="text-align:center; padding: 32px 16px;">
                <i class="fas fa-receipt" style="font-size: 44px; color: var(--light-gray); margin-bottom: 12px;"></i>
                <h3 style="color: var(--dark-gray); margin-bottom: 6px;">Belum ada pesanan</h3>
                <p style="color: var(--gray);">Jelajahi katalog dan ajukan sewa rumah atau villa pertama Anda.</p>
            </div>
        `;
        return;
    }

    container.innerHTML = orders.map(o => {
        let paymentNote = '';
        if (o.status === 'paid') {
            const method = o.payment_method_label ? ` via ${o.payment_method_label}` : '';
            paymentNote = `<span class="order-payment-note"><i class="fas fa-circle-check"></i> Pembayaran diterima${method}. Menunggu konfirmasi owner.</span>`;
        } else if (o.status === 'confirmed') {
            paymentNote = '<span class="order-payment-note"><i class="fas fa-house-circle-check"></i> Pesanan sudah dikonfirmasi owner.</span>';
        } else if (o.status === 'completed') {
            paymentNote = '<span class="order-payment-note"><i class="fas fa-flag-checkered"></i> Pesanan selesai.</span>';
        } else if (o.status === 'pending_payment') {
            const deadline = o.payment_expires_at ? `Bayar sebelum ${o.payment_expires_at}.` : 'Selesaikan pembayaran Anda.';
            paymentNote = `<span class="order-payment-note"><i class="fas fa-clock"></i> ${deadline}</span>`;
        } else if (o.status === 'rejected' && o.admin_note) {
            paymentNote = `<span class="order-payment-note"><i class="fas fa-circle-info"></i> ${escapeHtml(o.admin_note)}</span>`;
        }

        const payButton = o.status === 'pending_payment'
            ? `<a href="${o.payment_url}" class="btn-pay-order"><i class="fas fa-credit-card"></i> Bayar Sekarang</a>`
            : '';
        const invoiceButton = ['paid', 'confirmed', 'completed'].includes(o.status)
            ? `<a href="invoice.php?order_id=${o.id}" class="btn-pay-order"><i class="fas fa-file-invoice"></i> Invoice</a>`
            : '';
        const stayLabel = `${o.start_date_label || o.start_date} &ndash; ${o.checkout_date_label || o.end_date_label || o.checkout_date || o.end_date}`;

        return `
        <div class="order-card">
            <img src="${o.property_image}" alt="${o.property_name}">
            <div class="order-info">
                <h4>${o.property_name}</h4>
                <p><i class="fas fa-map-marker-alt"></i> ${o.property_location}</p>
                <p><i class="fas fa-calendar-alt"></i> ${stayLabel} &middot; ${o.days} malam</p>
                ${o.guest_summary ? `<p><i class="fas fa-users"></i> ${escapeHtml(o.guest_summary)}</p>` : ''}
                <p class="order-total">${formatPrice(o.total)}</p>
            </div>
            <span class="order-status status-${o.status}">${orderStatusLabels[o.status] || o.status_label || o.status}</span>
            ${(paymentNote || payButton || invoiceButton) ? `<div class="order-actions">${paymentNote}${payButton}${invoiceButton}</div>` : ''}
        </div>
    `;
    }).join('');
}

// ==================== CATEGORY FILTER ====================
safeInit(() => {
    document.querySelectorAll('.category-item').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.category-item').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderProperties();
        });
    });
});

// ==================== SEARCH ====================
safeInit(() => on('searchInput', 'input', () => renderProperties()));

['heroGuests', 'heroBedrooms', 'heroBudget'].forEach(id => {
    safeInit(() => on(id, 'change', () => renderProperties()));
});

safeInit(() => on('heroDateBtn', 'click', openSearchCalendar));

safeInit(() => on('heroDateClear', 'click', (event) => {
    event.stopPropagation();
    clearSearchDates(false);
}));

safeInit(() => on('catalogMeta', 'click', (event) => {
    const chip = event.target.closest('[data-clear-catalog]');
    if (chip) clearCatalogFilter(chip.dataset.clearCatalog);
}));

safeInit(() => on('heroSearchBtn', 'click', async () => {
    const location = document.getElementById('heroLocation').value.trim();
    if (location) {
        document.getElementById('searchInput').value = location;
        document.querySelectorAll('.category-item').forEach(button => button.classList.remove('active'));
        document.querySelector('.category-item[data-category="all"]')?.classList.add('active');
    }

    const ok = await refreshCatalogForSearch();
    if (ok) {
        document.getElementById('properties').scrollIntoView({ behavior: 'smooth' });
    }
}));

// ==================== MODALS ====================
const loginModal = document.getElementById('loginModal');
const registerModal = document.getElementById('registerModal');
const propertyModal = document.getElementById('propertyModal');
const ordersModal = document.getElementById('ordersModal');

const loginBtn = document.getElementById('loginBtn');
const registerBtn = document.getElementById('registerBtn');
const mobileLoginBtn = document.getElementById('mobileLoginBtn');
const mobileRegisterBtn = document.getElementById('mobileRegisterBtn');
const mobileLogoutBtn = document.getElementById('mobileLogoutBtn');
const logoutBtn = document.getElementById('logoutBtn');
const ordersBtn = document.getElementById('myOrdersBtn');

const mobileMenu = document.getElementById('mobileMenu');
const mobileOrdersBtn = document.getElementById('mobileOrdersBtn');

if (loginBtn) {
    loginBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openModal(loginModal);
    });
}

if (registerBtn) {
    registerBtn.addEventListener('click', () => openModal(registerModal));
}

if (mobileOrdersBtn) {
    mobileOrdersBtn.addEventListener('click', (e) => {
        e.preventDefault();
        mobileMenu.classList.remove('active');
        openOrders();
    });
}

if (mobileLoginBtn) {
    mobileLoginBtn.addEventListener('click', (e) => {
        e.preventDefault()
        document.getElementById('mobileMenu').classList.remove('active');
        openModal(loginModal);
    });
}

if (mobileRegisterBtn) {
    mobileRegisterBtn.addEventListener('click', () => {
        document.getElementById('mobileMenu').classList.remove('active');
        openModal(registerModal);
    });
}
if (logoutBtn) logoutBtn.addEventListener('click', handleLogout);
if (mobileLogoutBtn) mobileLogoutBtn.addEventListener('click', handleLogout);
if (ordersBtn) ordersBtn.addEventListener('click', openOrders);

on('switchToRegister', 'click', (e) => {
    e.preventDefault();
    closeModal(loginModal);
    openModal(registerModal);
});

on('switchToLogin', 'click', (e) => {
    e.preventDefault();
    closeModal(registerModal);
    openModal(loginModal);
});

// Tombol exit/tutup pada setiap modal
on('closeLogin', 'click', () => closeModal(loginModal));
on('closeRegister', 'click', () => closeModal(registerModal));
on('closeProperty', 'click', () => closeModal(propertyModal));
on('closeOrders', 'click', () => closeModal(ordersModal));

[loginModal, registerModal, propertyModal, ordersModal].forEach(modal => {
    if (modal) modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal(modal);
    });
});

// Tutup modal aktif dengan tombol Escape
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;

    const searchOverlay = document.getElementById('searchCalendarOverlay');
    if (searchOverlay && searchOverlay.classList.contains('active')) {
        closeSearchCalendar();
        return;
    }

    const stayOverlay = document.getElementById('stayCalendarOverlay');
    if (stayOverlay && stayOverlay.classList.contains('active')) {
        closeStayCalendar();
        return;
    }

    [loginModal, registerModal, propertyModal, ordersModal].forEach(modal => {
        if (modal && modal.classList.contains('active')) closeModal(modal);
    });
});

// Form submissions
on('loginForm', 'submit', handleLogin);
on('registerForm', 'submit', handleRegister);

// ==================== HAMBURGER MENU ====================
on('hamburger', 'click', () => {
    document.getElementById('mobileMenu').classList.toggle('active');
});

// ==================== INIT ====================
function init() {
    // Jam live memakai acuan jam server (WIB), bukan jam perangkat.
    syncServerClock(window.SERVER_NOW);
    updateLiveClock();
    updateHeroSearchDateUI();
    setInterval(updateLiveClock, 1000);

    const savedBooking = sessionStorage.getItem('pendingBooking');
    if (savedBooking) {
        sessionStorage.removeItem('pendingBooking');
        try {
            pendingBooking = JSON.parse(savedBooking);
        } catch (err) {
            pendingBooking = null;
        }
    }

    const welcome = sessionStorage.getItem('loginWelcome');
    if (welcome) sessionStorage.removeItem('loginWelcome');

    loadProperties().then(() => {
        if (pendingBooking) openPropertyDetail(pendingBooking.id);
        if (welcome) showToast(welcome);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}