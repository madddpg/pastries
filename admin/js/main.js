console.info('[admin] main.js loaded');

document.addEventListener("DOMContentLoaded", () => {
  console.info('[admin] DOMContentLoaded fired');
  // Products pagination page size (must be defined before initial apply)
  const PRODUCTS_PER_PAGE = 8;
  // Products search state
  const productsSearchState = { term: '' };

  // Track current section safely
  let currentSection = null;

  function showSection(sectionName) {
    if (sectionName === "dashboard") sectionName = "dashboard-overview";
    document.querySelectorAll(".content-section").forEach(s => s.classList.remove("active"));
    const targetSection = document.getElementById(`${sectionName}-section`);
    if (targetSection) {
      targetSection.classList.add("active");
      currentSection = sectionName;
      if (sectionName === 'order-history') {
        window.PickedUpOrders?.ensureLoaded();
      }
      // Ensure live orders list + pagination are populated when the section is shown
      if (sectionName === 'live-orders') {
        try {
          const activeTab = document.querySelector('#live-orders-tabs .tab.active');
          const status = activeTab ? (activeTab.dataset.status || '') : '';
          // small defer to allow DOM to settle
          setTimeout(() => { try { fetchOrders(status, 1); } catch(e) {} }, 0);
        } catch(_) {}
      }
    }
    document.querySelectorAll(".nav-item").forEach(item => {
      item.classList.remove("active");
      let itemSection = item.getAttribute("data-section");
      if (itemSection === "dashboard") itemSection = "dashboard-overview";
      if (itemSection === sectionName) item.classList.add("active");
    });
  }


  // Sidebar Navigation
  // Delegated handler for See receipt buttons
  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('.btn-see-receipt');
    if (!btn) return;
    ev.preventDefault();
    const url = btn.getAttribute('data-receipt-url') || '';
    if (url) {
      if (typeof window.__openReceipt === 'function') window.__openReceipt(url);
      else window.open(url, '_blank', 'noopener');
    } else {
      alert('No receipt image was attached for this order.');
    }
  }, true);
  document.querySelectorAll(".nav-item").forEach((item) => {
    item.addEventListener("click", function (e) {
      e.preventDefault();
      let sectionName = this.getAttribute("data-section");
      if (sectionName === "dashboard") sectionName = "dashboard-overview";
      showSection(sectionName);
    });
  });

  function getUrlParameter(name) {
    name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
    const regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
    const results = regex.exec(location.search);
    return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
  }

  if (getUrlParameter("status") !== "" || window.location.search.indexOf("status=") !== -1) {
    const status = getUrlParameter("status");
    showSection("live-orders");
    document.querySelectorAll("#live-orders-tabs .tab").forEach((tab) => {
      if ((tab.dataset.status || "") === status) tab.classList.add("active");
      else tab.classList.remove("active");
    });
    document.querySelectorAll(".nav-item").forEach((item) => {
      if (item.getAttribute("data-section") === "live-orders") item.classList.add("active");
      else item.classList.remove("active");
    });
    // NEW: Load orders for the requested status without navigation
    try { fetchOrders(status || ""); } catch (e) { /* fetchOrders defined later */ }
  } else {
    showSection("dashboard-overview");
  }
  // Tabs (for tab navigation, not sidebar)
  document.querySelectorAll(".tabs .tab").forEach((tab) => {
    tab.addEventListener("click", function (e) {
      // Skip live-orders tabs (they reload with ?status=)
      if (this.closest("#live-orders-tabs")) return;
      e.preventDefault();
      e.stopPropagation();
      const tabGroup = this.closest(".tabs");
      if (tabGroup) {
        tabGroup.querySelectorAll(".tab").forEach((t) => t.classList.remove("active"));
      }
      this.classList.add("active");

      // If this is the products filter tabs, filter rows by data_type
      if (tabGroup && tabGroup.id === 'products-filter-tabs') {
        const filter = (this.dataset.filter || 'all').toLowerCase();
        // Use pagination-aware flow
        applyProductsFilterAndPaginate(filter);
        return;
      }
    });
  });

  // Delegated handler as a fallback to ensure clicks are captured
  const productsTabs = document.getElementById('products-filter-tabs');
  const productsSearchInput = document.getElementById('products-search-input');
  const productsSearchClear = document.getElementById('products-search-clear');

  // Debounce helper
  function debounce(fn, delay = 250) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
  }

  function applyProductsSearch(term) {
    const v = String(term || '').trim();
    productsSearchState.term = v.toLowerCase();
    if (productsSearchClear) productsSearchClear.style.display = v ? 'block' : 'none';
    // Re-apply current filter + pagination from page 1
    const active = document.querySelector('#products-filter-tabs .tab.active');
    const filter = (active?.dataset.filter || 'all').toLowerCase();
    applyProductsFilterAndPaginate(filter);
  }

  if (productsSearchInput) {
    const onInput = debounce(() => applyProductsSearch(productsSearchInput.value), 250);
    productsSearchInput.addEventListener('input', onInput);
    productsSearchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        productsSearchInput.value = '';
        applyProductsSearch('');
        productsSearchInput.blur();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        applyProductsSearch(productsSearchInput.value);
      }
    });
  }
  if (productsSearchClear) {
    productsSearchClear.addEventListener('click', () => {
      if (productsSearchInput) productsSearchInput.value = '';
      applyProductsSearch('');
      if (productsSearchInput) productsSearchInput.focus();
    });
  }
  if (productsTabs) {
    productsTabs.addEventListener('click', function (e) {
      const a = e.target.closest('.tab');
      if (!a) return;
      e.preventDefault();
      e.stopPropagation();
      // Activate
      this.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      a.classList.add('active');
      // Apply filter + pagination
      const filter = (a.dataset.filter || 'all').toLowerCase();
      applyProductsFilterAndPaginate(filter);
    }, true); // capture to beat other handlers
    // Apply initial filter based on current active tab
    const active = productsTabs.querySelector('.tab.active');
    if (active) {
      applyProductsFilterAndPaginate((active.dataset.filter || 'all').toLowerCase());
    } else {
      applyProductsFilterAndPaginate('all');
    }
  }

  // --- Products pagination (5 per page) ---
  function getFilteredProductRows(filter = 'all') {
    const tbody = document.querySelector('#products-section .products-table tbody');
    if (!tbody) return [];
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const typeFiltered = rows.filter(tr => {
      const type = (tr.getAttribute('data-product-type') || '').toLowerCase();
      return filter === 'all' || type === filter;
    });
    const term = productsSearchState.term;
    if (!term) return typeFiltered;
    return typeFiltered.filter(tr => {
      const id = (tr.getAttribute('data-product-id') || '').toLowerCase();
      const name = (tr.getAttribute('data-product-name') || '').toLowerCase();
      const status = (tr.getAttribute('data-product-status') || '').toLowerCase();
      const cat = (tr.getAttribute('data-product-category') || '').toLowerCase();
      return id.includes(term) || name.includes(term) || status.includes(term) || cat.includes(term);
    });
  }

  function renderProductsPage(rows, page = 1) {
    const tbody = document.querySelector('#products-section .products-table tbody');
    if (!tbody) return;
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / PRODUCTS_PER_PAGE));
    const clamped = Math.min(Math.max(1, page), totalPages);
    const start = (clamped - 1) * PRODUCTS_PER_PAGE;
    const end = start + PRODUCTS_PER_PAGE;
    const set = new Set(rows.slice(start, end));

    // Show only rows in the current page; hide the rest (but keep filter state)
    // Remove any previous no-results row
    const prevNo = tbody.querySelector('tr.no-results-row');
    if (prevNo) prevNo.remove();
    Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
      if (rows.includes(tr)) {
        tr.style.display = set.has(tr) ? '' : 'none';
      } else {
        tr.style.display = 'none';
      }
    });

    // If no results, show a friendly message and clear pagination
    if (total === 0) {
      const no = document.createElement('tr');
      no.className = 'no-results-row';
      const td = document.createElement('td');
      td.colSpan = 9; // matches the number of header columns
      td.style.cssText = 'text-align:center;padding:12px;color:#64748b;';
      td.textContent = productsSearchState.term ? 'No products match your search.' : 'No products found.';
      no.appendChild(td);
      tbody.appendChild(no);
      const pag = document.getElementById('products-pagination');
      if (pag) pag.innerHTML = '';
      return;
    }

    renderProductsPagination(totalPages, clamped);
  }

  function renderProductsPagination(totalPages, current) {
    const pag = document.getElementById('products-pagination');
    if (!pag) return;
    pag.innerHTML = '';
    if (totalPages <= 1) return;

    const mkBtn = (label, page, disabled = false, active = false) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pager-btn' + (active ? ' active' : '');
      btn.textContent = label;
      btn.disabled = disabled;
      btn.dataset.page = String(page);
      return btn;
    };

    // Prev
    pag.appendChild(mkBtn('Prev', Math.max(1, current - 1), current === 1));

    // Numbers (compact for few pages since 5/page)
    for (let p = 1; p <= totalPages; p++) {
      pag.appendChild(mkBtn(String(p), p, false, p === current));
    }

    // Next
    pag.appendChild(mkBtn('Next', Math.min(totalPages, current + 1), current === totalPages));

    pag.addEventListener('click', onProductsPaginateClick, { once: true });
  }

  function onProductsPaginateClick(e) {
    const btn = e.target.closest('button.pager-btn');
    if (!btn) return;
    // Close any open product dropdown before re-render
    document.querySelectorAll('#products-section .dropdown-menu').forEach(m => { m.style.display = 'none'; m.classList?.remove('open-up'); });
    const page = parseInt(btn.dataset.page || '1', 10) || 1;
    const active = document.querySelector('#products-filter-tabs .tab.active');
    const filter = (active?.dataset.filter || 'all').toLowerCase();
    const rows = getFilteredProductRows(filter);
    renderProductsPage(rows, page);
    // Rebind for subsequent clicks
    const pag = document.getElementById('products-pagination');
    if (pag) pag.addEventListener('click', onProductsPaginateClick, { once: true });
  }

  function applyProductsFilterAndPaginate(filter = 'all') {
    // Update header labels based on filter
    try {
      const hA = document.getElementById('col-price-a');
      const hB = document.getElementById('col-price-b');
      const hC = document.getElementById('col-price-c');
      const table = document.getElementById('products-table');
      if (hA && hB && hC) {
        if (filter === 'pastries') {
          hA.textContent = 'Per piece';
          hB.textContent = 'Box of 4';
          hC.textContent = 'Box of 6';
          table?.classList.remove('mode-drinks');
        } else if (filter === 'hot' || filter === 'cold') {
          hA.textContent = 'Grande';
          hB.textContent = 'Supreme';
          hC.textContent = '';
          table?.classList.add('mode-drinks');
        } else {
          // All: default to generic labels
          hA.textContent = 'Per piece / Grande';
          hB.textContent = 'Box of 4 / Supreme';
          hC.textContent = 'Box of 6 / —';
          table?.classList.remove('mode-drinks');
        }
      }
    } catch (_) {}

    const rows = getFilteredProductRows(filter);
    renderProductsPage(rows, 1);
  }

  // Hook handled in delegated productsTabs listener above

  // Live Orders Tabs: SPA behavior (no page reload)
  const liveOrdersTabs = document.querySelectorAll('#live-orders-tabs .tab');
  liveOrdersTabs.forEach(tab => {
    // Use capture to prevent earlier handlers (from inline scripts) from firing
    tab.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      // Activate selected tab
      liveOrdersTabs.forEach(t => t.classList.remove('active'));
      this.classList.add('active');

  // Show section and load orders
  const status = this.dataset.status || '';
  showSection('live-orders');
  try { fetchOrders(status, 1); } catch (e) { /* fetchOrders defined later */ }

  // Update URL without navigating, preserving location and name search
  const locSel = document.getElementById('live-location-filter');
  const nameInput = document.getElementById('live-name-search');
  const location = locSel ? (locSel.value || '') : '';
  const qVal = nameInput ? (nameInput.value || '').trim() : '';
  const params = new URLSearchParams();
  params.set('status', status);
  if (location) params.set('location', location);
  if (qVal) params.set('q', qVal);
  const newUrl = '?' + params.toString();
  history.replaceState(null, '', newUrl);
    }, true); // capture phase
  });

  // Location filter change handler for Live Orders
  const liveLocationFilter = document.getElementById('live-location-filter');
  if (liveLocationFilter) {
    liveLocationFilter.addEventListener('change', () => {
      const activeTab = document.querySelector('#live-orders-tabs .tab.active');
      const status = activeTab ? (activeTab.dataset.status || '') : '';
      try { fetchOrders(status, 1); } catch (e) {}
      // Keep URL in sync
      const params = new URLSearchParams(window.location.search);
      params.set('status', status);
      const locVal = liveLocationFilter.value || '';
      if (locVal) params.set('location', locVal); else params.delete('location');
      // keep q
      const qEl = document.getElementById('live-name-search');
      const qVal = (qEl && qEl.value) ? qEl.value.trim() : '';
      if (qVal) params.set('q', qVal); else params.delete('q');
      history.replaceState(null, '', '?' + params.toString());
    });
  }

  // Initialize location selector from URL on load
  (function initLiveLocationFromUrl() {
    const sel = document.getElementById('live-location-filter');
    if (!sel) return;
    const params = new URLSearchParams(window.location.search);
    const loc = params.get('location') || '';
    if (!loc) return;
    for (const opt of sel.options) {
      if (opt.value === loc) { sel.value = loc; break; }
    }
  })();

  // Initialize live name search from URL and wire up events
  (function initLiveNameSearch() {
    const input = document.getElementById('live-name-search');
    if (!input) return;
    const params = new URLSearchParams(window.location.search);
    const q = params.get('q') || '';
    if (q) input.value = q;

    let t = null;
    const trigger = () => {
      const activeTab = document.querySelector('#live-orders-tabs .tab.active');
      const status = activeTab ? (activeTab.dataset.status || '') : '';
      try { fetchOrders(status, 1); } catch (e) {}
      // sync URL
      const locSel = document.getElementById('live-location-filter');
      const location = locSel ? (locSel.value || '') : '';
      const params = new URLSearchParams(window.location.search);
      params.set('status', status);
      if (location) params.set('location', location); else params.delete('location');
      const val = (input.value || '').trim();
      if (val) params.set('q', val); else params.delete('q');
      history.replaceState(null, '', '?' + params.toString());
    };

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); trigger(); }
    });
    input.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(trigger, 400); // debounce
    });
  })();

  // Busy Mode Toggle (optional)
  const busyModeToggle = document.querySelector(".toggle input");
  if (busyModeToggle) {
    busyModeToggle.addEventListener("change", function () {
      const status = this.checked ? "enabled" : "disabled";
      showNotification(`Busy mode ${status}`);
      const shopStatus = document.querySelector(".shop-status");
      if (shopStatus) {
        if (this.checked) {
          shopStatus.classList.remove("open");
          shopStatus.classList.add("closed");
          const span = shopStatus.querySelector("span:first-child");
          if (span) span.textContent = "Closed For Order";
        } else {
          shopStatus.classList.remove("closed");
          shopStatus.classList.add("open");
          const span = shopStatus.querySelector("span:first-child");
          if (span) span.textContent = "Open For Order";
        }
      }
    });
  }

  // Reports: Download CSV
  (function reportsDownloadInit() {
    const btn = document.getElementById('btn-download-report');
    if (!btn) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      // Collect filters
      const monthEl = document.getElementById('report-month');
      const locEl = document.getElementById('report-location');
      const typeEl = document.getElementById('report-type');
      const monthVal = monthEl ? (monthEl.value || '') : '';
      let from = '', to = '';
      if (monthVal) {
        // monthVal = YYYY-MM, compute first and last day
        const [y, m] = monthVal.split('-').map(x => parseInt(x, 10));
        if (y && m) {
          const first = new Date(y, m - 1, 1);
          const last = new Date(y, m, 0); // day 0 of next month
          const pad = n => String(n).padStart(2, '0');
          from = `${first.getFullYear()}-${pad(first.getMonth() + 1)}-${pad(first.getDate())}`;
          to = `${last.getFullYear()}-${pad(last.getMonth() + 1)}-${pad(last.getDate())}`;
        }
      }
      const location = locEl ? (locEl.value || '') : '';
      const type = typeEl ? (typeEl.value || '') : '';
  const url = new URL('download_report.php', window.location.href);
  url.searchParams.set('view', 'transactions');
      if (from) url.searchParams.set('from', from);
      if (to) url.searchParams.set('to', to);
      if (location) url.searchParams.set('location', location);
      if (type) url.searchParams.set('type', type);
      // Open in same tab to trigger download
      window.location.href = url.toString();
    });
  })();

(function () {
  // Correct API path relative to admin.php
  const API = 'AJAX/get_toppings.php';

  async function fetchToppings() {
    try {
      const res = await fetch(`${API}?action=list`, { credentials: 'same-origin' });
      const data = await res.json();
      const tbody = document.querySelector('#toppingsTable tbody');
      if (!tbody) return;

      if (!data || !data.success) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:#c0392b">Failed to load toppings</td></tr>';
        return;
      }

      const isSuper = !!data.is_super;

      function esc(html) {
        return String(html || '').replace(/[&<>"']/g, s => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;'
        }[s]));
      }

      tbody.innerHTML = data.toppings.map(t => {
        const idVal = t?.topping_id ? String(t.topping_id) : '';
        const status = (t.status === 'active') ? 'active' : 'inactive';

        return `
          <tr data-topping-id="${idVal}" data-status="${status}">
            <td style="width:60px;">${idVal || '—'}</td>
            <td>${esc(t.name)}</td>
            <td style="text-align:right;">₱${Number(t.price).toFixed(2)}</td>
            <td style="text-align:center;">
              <span class="status-badge ${status}">
                ${status.charAt(0).toUpperCase() + status.slice(1)}
              </span>
            </td>
            <td style="text-align:center;white-space:nowrap;">
              <button class="btn-edit-topping" data-topping-id="${idVal}">Edit</button>
              <button class="btn-topping-price-history" data-topping-id="${idVal}" style="margin-left:8px;color:#059669;">Price history</button>
              <button class="btn-toggle-topping" data-topping-id="${idVal}" data-status="${status}" style="margin-left:8px;">
                ${status === 'active' ? 'Set Inactive' : 'Set Active'}
              </button>
              ${isSuper ? `<button class="btn-delete-topping" data-topping-id="${idVal}" style="margin-left:8px;color:#ef4444;">Delete</button>` : ''}
            </td>
          </tr>
        `;
      }).join('');
    } catch (err) {
      console.error('fetchToppings error', err);
      const tbody = document.querySelector('#toppingsTable tbody');
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:#c0392b">Server error: check console</td></tr>';
      }
    }
  }

  async function loadActiveToppings() {
    try {
      const res = await fetch(`${API}?action=active`, { cache: 'no-store', credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success || !Array.isArray(data.toppings)) return;
      const container = document.getElementById('toppingsList');
      if (!container) return;
      container.innerHTML = data.toppings.map(t => {
        const idVal = t?.topping_id ?? '';
        const safeName = (t.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        return `<label style="display:block;margin-bottom:6px;">
          <input type="checkbox" class="topping-checkbox" data-topping-id="${idVal}" data-price="${Number(t.price).toFixed(2)}"> 
          ${safeName} — ₱${Number(t.price).toFixed(2)}
        </label>`;
      }).join('');
      bindToppingCheckboxes();
      if (typeof recalcModalTotal === 'function') recalcModalTotal();
    } catch (err) {
      console.error('loadActiveToppings error', err);
    }
  }

  function bindToppingCheckboxes() {
    document.querySelectorAll('.topping-checkbox').forEach(cb => {
      cb.onchange = function () {
        const key = cb.getAttribute('data-topping-id');
        const price = parseFloat(cb.getAttribute('data-price')) || 0;
        if (cb.checked) {
          modalSelectedToppings[key] = { price, qty: 1, name: cb.parentNode.textContent.trim() };
        } else {
          delete modalSelectedToppings[key];
        }
        recalcModalTotal();
      };
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    loadActiveToppings();
  });

  fetchToppings();

  const showBtn = document.getElementById('showAddToppingModalBtn');
  const addModal = document.getElementById('addToppingModal');
  const closeBtn = document.getElementById('closeAddToppingModal');
  const cancelBtn = document.getElementById('cancelToppingBtn');
  const form = document.getElementById('toppingForm');
  const resultEl = document.getElementById('toppingFormResult');

  if (showBtn) {
    showBtn.addEventListener('click', function () {
      document.getElementById('addToppingTitle').textContent = 'Add Topping';
      document.getElementById('toppingId').value = '';
      document.getElementById('toppingName').value = '';
      document.getElementById('toppingPrice').value = '';
      if (addModal) addModal.style.display = 'flex';
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', () => { if (addModal) addModal.style.display = 'none'; });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { if (addModal) addModal.style.display = 'none'; });

  // delegated actions: edit / toggle / delete
  document.body.addEventListener('click', async function (e) {
    const target = e.target;
    if (target.matches('.btn-topping-price-history')) {
      const id = target.getAttribute('data-topping-id');
      if (!id) return;
      try {
        const url = new URL('AJAX/fetch_topping_price_history.php', window.location.href);
        url.searchParams.set('topping_id', id);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok || !data || !data.success) throw new Error(data?.message || `Failed (${res.status})`);
        const list = Array.isArray(data.data) ? data.data : [];
        if (list.length === 0) {
          window.__openPriceHistory('<div style="padding:12px;color:#64748b;">No price history found.</div>');
          return;
        }
        const headers = ['Price (₱)','Effective From','Effective To'];
        const th = headers.map(h => `<th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">${h}</th>`).join('');
        const rows = list.map(r => {
          const price = Number(r.price || 0).toFixed(2);
          const from = r.effective_from || '';
          const to = r.effective_to || '';
          return `<tr>`+
            `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">₱${price}</td>`+
            `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">${from}</td>`+
            `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">${to || '—'}</td>`+
          `</tr>`;
        }).join('');
        const html = `<table style="width:100%;border-collapse:collapse;font-size:14px;">`+
          `<thead><tr>${th}</tr></thead>`+
          `<tbody>${rows}</tbody>`+
        `</table>`;
        window.__openPriceHistory(html);
      } catch (err) {
        console.error('[admin] topping price history error', err);
        window.__openPriceHistory('<div style="padding:12px;color:#dc2626;">Failed to load history.</div>');
      }
      return;
    }

    if (target.matches('.btn-edit-topping')) {
      const id = target.getAttribute('data-topping-id');
      const row = document.querySelector(`#toppingsTable tr[data-topping-id="${id}"]`);
      if (!row) return;
      document.getElementById('addToppingTitle').textContent = 'Edit Topping';
      document.getElementById('toppingId').value = id;
      document.getElementById('toppingName').value = row.children[1].textContent;
      document.getElementById('toppingPrice').value = parseFloat(row.children[2].textContent.replace('₱', '')) || 0;
      // Note: allowed types/categories are not displayed in the list; if we need prefill, we'd fetch per-topping scope via an endpoint.
      if (addModal) addModal.style.display = 'flex';
      return;
    }

    if (target.matches('.btn-toggle-topping')) {
      const id = target.getAttribute('data-topping-id');
      const current = target.getAttribute('data-status') === 'active' ? 1 : 0;
      const next = current === 1 ? 0 : 1;
      // Warn admin when deactivating a topping
      if (next === 0) {
        const name = (document.querySelector(`#toppingsTable tr[data-topping-id="${id}"] td:nth-child(2)`)?.textContent || '').trim() || `ID ${id}`;
        const confirmMsg = `The "${name}" will be inactive and removed from customers' menu options until reactivated. Continue?`;
        if (!confirm(confirmMsg)) return;
      }
      const body = new URLSearchParams();
      body.append('action', 'toggle_status');
      body.append('topping_id', id);
      body.append('status', next === 1 ? 'active' : 'inactive');
      console.log('[DEBUG toggle request]', body.toString());
      try {
        const res = await fetch(API, { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        console.log('[DEBUG toggle response]', data);
        if (data.success) {
          fetchToppings();
          await loadActiveToppings();
        }
      } catch (err) {
        console.error('toggle topping error', err);
      }
      return;
    }

    if (target.matches('.btn-delete-topping')) {
      const id = target.getAttribute('data-topping-id');
      const row = document.querySelector(`#toppingsTable tr[data-topping-id="${id}"]`);
      const name = row ? row.children[1].textContent.trim() : ('ID ' + id);
      if (!confirm(`Delete topping "${name}"?\nThis cannot be undone.`)) return;

      const body = new URLSearchParams();
      body.append('action', 'delete');
      body.append('topping_id', id);
      console.log('[DEBUG delete body]', body.toString());
      try {
        const res = await fetch(API, { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
          fetchToppings();
          await loadActiveToppings();
          showNotification(`Deleted topping: ${name}`);
        } else {
          alert('Delete failed: ' + (data.message || 'unknown'));
        }
      } catch (err) {
        console.error('delete topping error', err);
        alert('Delete request failed: ' + (err.message || 'network error'));
      }
      return;
    }
  });

  // save form
  if (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const id = document.getElementById('toppingId').value;
      const name = document.getElementById('toppingName').value.trim();
      const price = document.getElementById('toppingPrice').value;
      if (!name) { if (resultEl) resultEl.textContent = 'Name required'; return; }
      const body = new URLSearchParams();
      body.append('name', name);
      body.append('price', price);
      if (!id) {
        body.append('action', 'add');
      } else {
        body.append('action', 'update');
        body.append('topping_id', id);
      }
      try {
        const res = await fetch(API, { method: 'POST', body, credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
          if (addModal) addModal.style.display = 'none';
          fetchToppings();
          await loadActiveToppings();
        } else {
          if (resultEl) resultEl.textContent = data.message || 'Failed';
        }
      } catch (err) {
        console.error('save topping error', err);
        if (resultEl) resultEl.textContent = 'Request failed';
      }
    });
  }
})();



  // Make table rows clickable for order details (guard currentSection)
  const tableRows = document.querySelectorAll(".orders-table tbody tr, .products-table tbody tr, .stock-table tbody tr");
  tableRows.forEach((row) => {
    row.addEventListener("click", function (e) {
      if (e.target.closest(".action-btn") || e.target.closest(".menu-item")) return;
      const orderIdCell = this.querySelector("td:first-child");
      const orderId = orderIdCell ? orderIdCell.textContent : null;

      if (currentSection === "products") {
        const productName = this.querySelector(".product-cell h4")?.textContent || '';
        if (productName) showNotification(`Viewing details for ${productName}`);
      } else if (currentSection === "stock") {
        const itemName = this.querySelector(".item-cell h4")?.textContent || '';
        if (itemName) showNotification(`Viewing details for ${itemName}`);
      } else if (currentSection === "live-orders" && orderId) {
        // If you want to open order details on live-orders:
        // showOrderDetails(orderId);
      }
    });

    if (!row.closest("#order-history-section")) {
      row.classList.add("clickable-row");
    }
  });

  // Live Order Action Buttons (toast only; real handlers attached after fetch)
  const orderActionBtns = document.querySelectorAll(".btn-accept, .btn-reject, .btn-ready, .btn-cancel, .btn-complete, .btn-notify");
  orderActionBtns.forEach((btn) => {
    btn.addEventListener("click", function () {
      showNotification(`Order ${this.textContent.trim()} action performed`);
    });
  });

  // Offer Action Buttons
  const offerActionBtns = document.querySelectorAll(".btn-edit, .btn-pause, .btn-delete, .btn-activate");
  offerActionBtns.forEach((btn) => {
    btn.addEventListener("click", function () {
      showNotification(`${this.textContent.trim()} action performed`);
    });
  });

  // Settings Navigation
  const settingsNavItems = document.querySelectorAll(".settings-nav-item");
  settingsNavItems.forEach((item) => {
    item.addEventListener("click", function (e) {
      e.preventDefault();
      document.querySelectorAll(".settings-nav-item").forEach((nav) => nav.classList.remove("active"));
      this.classList.add("active");
      showNotification(`Switched to ${this.textContent} settings`);
    });
  });

  // Messaging
  const messageInput = document.querySelector(".message-input input");
  const sendBtn = document.querySelector(".btn-send");
  if (messageInput && sendBtn) {
    sendBtn.addEventListener("click", () => {
      if (messageInput.value.trim()) {
        const messageHistory = document.querySelector(".message-history");
        const newMessage = document.createElement("div");
        newMessage.className = "message admin";
        newMessage.innerHTML = `
          <div class="message-bubble">
            <p>${messageInput.value}</p>
            <span class="message-time">${new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</span>
          </div>
        `;
        if (messageHistory) {
          messageHistory.appendChild(newMessage);
          messageHistory.scrollTop = messageHistory.scrollHeight;
        }
        messageInput.value = "";
        showNotification("Message sent");
      }
    });
    messageInput.addEventListener("keypress", function (e) {
      if (e.key === "Enter" && this.value.trim()) sendBtn.click();
    });
  }

  // Conversation list
  const conversationItems = document.querySelectorAll(".conversation-item");
  conversationItems.forEach((item) => {
    item.addEventListener("click", function () {
      document.querySelectorAll(".conversation-item").forEach((conv) => conv.classList.remove("active"));
      this.classList.add("active");
      const unreadBadge = this.querySelector(".unread-badge");
      if (unreadBadge) unreadBadge.remove();
      const customerName = this.querySelector("h4")?.textContent || "customer";
      showNotification(`Opened conversation with ${customerName}`);
    });
  });

  // Buttons
  const actionButtons = document.querySelectorAll(".btn-primary, .btn-secondary");
  actionButtons.forEach((btn) => {
    btn.addEventListener("click", function () {
      showNotification(`${this.textContent.trim()} clicked`);
    });
  });

  // Settings inputs
  const settingInputs = document.querySelectorAll(".setting-input, .setting-select");
  settingInputs.forEach((input) => {
    input.addEventListener("change", () => {
      showNotification("Setting updated");
    });
  });

  // User/Notifications icons
  const userProfile = document.querySelector(".user-profile");
  if (userProfile) userProfile.addEventListener("click", () => showNotification("User profile menu opened"));

  const notificationIcon = document.querySelector(".notification-icon");
  if (notificationIcon) notificationIcon.addEventListener("click", () => showNotification("Notifications panel opened"));

  // Enhanced Toast notification system
  function showNotification(message, type = 'success') {
    const notification = document.createElement("div");
    notification.className = `notification notification-${type}`;
    
    // Add icon based on type
    const icon = type === 'error' ? '⚠️' : type === 'success' ? '✅' : 'ℹ️';
    notification.innerHTML = `
      <span class="notification-icon">${icon}</span>
      <span class="notification-message">${message}</span>
    `;
    
    notification.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      background: ${type === 'error' ? '#f44336' : type === 'success' ? '#4caf50' : '#2196f3'};
      color: white;
      padding: 12px 16px;
      border-radius: 8px;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 10000;
      display: flex;
      align-items: center;
      gap: 8px;
      transform: translateX(100%);
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      max-width: 300px;
    `;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
      notification.style.transform = 'translateX(0)';
    }, 10);
    
    // Animate out
    setTimeout(() => {
      notification.style.transform = 'translateX(100%)';
      setTimeout(() => notification.remove(), 300);
    }, type === 'error' ? 5000 : 3000);
    
    // Click to dismiss
    notification.addEventListener('click', () => {
      notification.style.transform = 'translateX(100%)';
      setTimeout(() => notification.remove(), 300);
    });
  }



  document.addEventListener('click', function (e) {
    // If the confirmation modal is open and the click is inside it, ignore live-order button handling
    const modal = document.getElementById('confirmActionModal');
    if (modal && modal.style && modal.style.display === 'flex' && e.target && modal.contains(e.target)) {
      return;
    }
    const btn = e.target.closest('.btn-accept, .btn-ready, .btn-complete, .btn-reject');
    if (!btn) return;
    // Prevent any form submission or inline handlers
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    // Enhanced visual feedback: only apply immediate loading/text for Reject (Accept waits for confirmation)
    if (btn.classList.contains('btn-reject')) {
      btn.classList.add('loading');
      const ripple = document.createElement('span');
      ripple.className = 'btn-ripple';
      const rect = btn.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      ripple.style.width = ripple.style.height = size + 'px';
      ripple.style.left = (e.clientX - rect.left - size/2) + 'px';
      ripple.style.top = (e.clientY - rect.top - size/2) + 'px';
      btn.appendChild(ripple);
      setTimeout(() => ripple.remove(), 500);
      const textSpan = btn.querySelector('.btn-text');
      if (textSpan && !btn.dataset.originalText) btn.dataset.originalText = textSpan.textContent || '';
      if (textSpan) textSpan.textContent = 'Cancelling order...';
      showActionFeedback(btn, 'reject');
    }

    const map = {
      'btn-accept': 'preparing',
      'btn-ready': 'ready',
      'btn-complete': 'picked up',
      'btn-reject': 'cancelled',
    };
    const cls = Object.keys(map).find(k => btn.classList.contains(k));
    if (!cls) return;

    const orderId = getOrderIdFrom(btn);
    if (!orderId) {
      console.error('[admin] live action: unable to get order id', btn);
      return;
    }
    const nextStatus = map[cls];
    console.info('[admin] live action:', { id: orderId, status: nextStatus });

    // For Accept only, show a modal confirmation
    if (cls === 'btn-accept') {
      // Reset confirm handled flag
      try { window.__confirmHandled = false; } catch (_) {}
      openConfirmAction({
        title: 'Accept order?',
        message: 'This will move the order to Preparing.',
        confirmLabel: 'Accept',
        onConfirm: () => { try { window.__confirmHandled = true; } catch(_) {} performUpdate(btn, orderId, nextStatus); }
      });
      return;
    }

    // Others proceed immediately
    performUpdate(btn, orderId, nextStatus);
  }, true); // capture to block inline onclicks if any

  // Enhanced feedback function for Accept/Reject actions
  function showActionFeedback(button, action) {
    const feedbackEl = document.createElement('div');
    feedbackEl.className = `action-feedback action-feedback-${action}`;
    feedbackEl.style.cssText = `
      position: absolute;
      top: -8px;
      left: 50%;
      transform: translateX(-50%) translateY(-100%);
      background: ${action === 'accept' ? '#4caf50' : '#f44336'};
      color: white;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 12px;
      font-weight: 600;
      z-index: 1000;
      opacity: 0;
      transition: opacity 0.3s ease;
      pointer-events: none;
      white-space: nowrap;
    `;
    feedbackEl.textContent = action === 'accept' ? 'Processing...' : 'Cancelling...';
    
    button.style.position = 'relative';
    button.appendChild(feedbackEl);
    
    // Animate in
    setTimeout(() => feedbackEl.style.opacity = '1', 10);
    
    // Remove after delay
    setTimeout(() => {
      feedbackEl.style.opacity = '0';
      setTimeout(() => feedbackEl.remove(), 300);
    }, 2000);
  }

  // Centralized update with consistent button UX
  function performUpdate(btn, orderId, nextStatus) {
  try { console.info('[admin] performUpdate start', { orderId: String(orderId), nextStatus }); } catch (_) {}
    const prevDisabled = btn.disabled;
    const isAccept = nextStatus === 'preparing';
    // Apply loading/text now (for Accept after confirmation; for Reject we may have already applied)
    btn.classList.add('loading');
    const textSpan = btn.querySelector('.btn-text');
    if (textSpan && !btn.dataset.originalText) btn.dataset.originalText = textSpan.textContent || '';
    if (textSpan) textSpan.textContent = isAccept ? 'Accepting order...' : (nextStatus === 'cancelled' ? 'Cancelling order...' : textSpan.textContent);
    btn.disabled = true;
    updateOrderStatus(orderId, nextStatus)
      .then((resp) => {
        const isReject = nextStatus === 'cancelled';
        if (isAccept || isReject) {
          const after = resp && resp.status_after ? String(resp.status_after) : nextStatus;
          showNotification(isAccept ? `Order accepted → ${after}` : 'Order cancelled successfully!');
        }
        // If we accepted, jump to the Preparing tab to display the updated order
        if (isAccept) {
          try {
            const tabs = document.querySelectorAll('#live-orders-tabs .tab');
            tabs.forEach(t => t.classList.remove('active'));
            const prepTab = document.querySelector('#live-orders-tabs .tab[data-status="preparing"]');
            if (prepTab) prepTab.classList.add('active');
            // Update URL query params to reflect preparing view
            const locSel = document.getElementById('live-location-filter');
            const nameInput = document.getElementById('live-name-search');
            const location = locSel ? (locSel.value || '') : '';
            const qVal = nameInput ? (nameInput.value || '').trim() : '';
            const params = new URLSearchParams();
            params.set('status', 'preparing');
            if (location) params.set('location', location);
            if (qVal) params.set('q', qVal);
            history.replaceState(null, '', '?' + params.toString());
            // Fetch preparing list page 1
            try { console.debug('[admin] fetchOrders preparing (immediate)'); } catch(_) {}
            fetchOrders('preparing', 1);
            // Extra safety: refetch after a short delay to beat any DB replication or caching race
            setTimeout(() => { try { console.debug('[admin] fetchOrders preparing (delayed)'); fetchOrders('preparing', 1); } catch(e) {} }, 450);
          } catch (_) { /* ignore */ }
        }
      })
      .catch((error) => {
        console.error('[admin] Action failed:', error);
        showNotification(isAccept ? 'Failed to accept order' : 'Failed to update order', 'error');
      })
      .finally(() => {
        btn.disabled = prevDisabled;
        btn.classList.remove('loading');
        const textSpan = btn.querySelector('.btn-text');
        if (textSpan && btn.dataset.originalText !== undefined) {
          textSpan.textContent = btn.dataset.originalText;
        }
        delete btn.dataset.originalText;
      });
  }

  // Confirmation modal wiring
  function openConfirmAction({ title = 'Confirm', message = 'Are you sure?', confirmLabel = 'Confirm', onConfirm = null } = {}) {
    const modal = document.getElementById('confirmActionModal');
    if (!modal) { if (typeof onConfirm === 'function') onConfirm(); return; }
    const elTitle = document.getElementById('confirmActionTitle');
    const elMsg = document.getElementById('confirmActionMessage');
    const btnOk = document.getElementById('confirmActionOk');
    const btnCancel = document.getElementById('confirmActionCancel');
    const btnClose = document.getElementById('confirmActionClose');

    if (elTitle) elTitle.textContent = title;
    if (elMsg) elMsg.textContent = message;
    if (btnOk) btnOk.textContent = confirmLabel;

    // Store pending action as a global fallback in case event binding is disrupted
    try { window.__pendingConfirmAction = (typeof onConfirm === 'function') ? onConfirm : null; } catch (_) {}
  try { console.info('[admin] openConfirmAction wired', { hasOk: !!btnOk, hasCancel: !!btnCancel, hasClose: !!btnClose }); } catch (_) {}

  function close() { modal.style.display = 'none'; cleanup(); }
    function cleanup() {
      btnOk && btnOk.removeEventListener('click', onOk);
      btnCancel && btnCancel.removeEventListener('click', onCancel);
      btnClose && btnClose.removeEventListener('click', onCancel);
      modal && modal.removeEventListener('click', onBackdrop);
    }
  function onOk() { try { console.info('[admin] confirmAction OK clicked'); if (typeof onConfirm === 'function') onConfirm(); } finally { try { window.__confirmHandled = true; } catch(_) {} close(); } }
    function onCancel() { close(); }
    function onBackdrop(e) { if (e.target && e.target.getAttribute('data-close') === 'backdrop') close(); }

    if (btnOk) {
      btnOk.addEventListener('click', onOk, { once: true });
      // Also set onclick for robustness if another script interferes with listeners
      btnOk.onclick = onOk;
    }
    btnCancel && btnCancel.addEventListener('click', onCancel, { once: true });
    btnClose && btnClose.addEventListener('click', onCancel, { once: true });
    modal && modal.addEventListener('click', onBackdrop, { once: true });
    modal.style.display = 'flex';

    // Allow Enter key to confirm while modal is visible
    function onKey(e){ if (e.key === 'Enter') { e.preventDefault(); onOk(); } }
    document.addEventListener('keydown', onKey, { once: true });
  }

  // Global fallback: if OK button click isn't wired, this ensures action still runs
  document.addEventListener('click', function (e) {
    const ok = e.target && e.target.closest && e.target.closest('#confirmActionOk');
    if (!ok) return;
    try { console.info('[admin] confirmAction OK clicked (fallback)'); } catch (_) {}
    try {
      if (typeof window.__pendingConfirmAction === 'function') {
        window.__pendingConfirmAction();
        window.__pendingConfirmAction = null;
      }
    } catch (_) {}
    // Close modal if present
    const modal = document.getElementById('confirmActionModal');
    if (modal) modal.style.display = 'none';
  }, true);

  // Removed duplicate toppings management block (loadToppings + handlers) to avoid conflicts.

  console.info('[admin] main.js loaded');



  (function enforceToppingDeleteVisibility() {
    const tbody = document.querySelector('#toppingsTable tbody');
    // Use server flag if available; default to false (hide) for safety
    const isSuper = !!window.IS_SUPER_ADMIN;

    if (!tbody) return;

    function clean() {
      if (isSuper) return;
      tbody.querySelectorAll('td').forEach(td => {
        td.querySelectorAll('button').forEach(btn => {
          if (btn.textContent && btn.textContent.trim().toLowerCase() === 'delete') btn.remove();
        });
      });
    }

    // initial pass
    clean();

    // watch for future inserted rows (AJAX / re-renders)
    const mo = new MutationObserver(clean);
    mo.observe(tbody, { childList: true, subtree: true });
  })();

  // Order details modal
  function showOrderDetails(orderId) {
    fetch(`order_detail.php?id=${encodeURIComponent(orderId)}`)
      .then(res => res.text())
      .then(html => {
        const modal = document.createElement("div");
        modal.className = "modal";
        modal.innerHTML = html;
        modal.addEventListener("click", (e) => { if (e.target === modal) modal.remove(); });
        setTimeout(() => {
          const closeBtn = modal.querySelector(".close-btn, .btn-close, .modal-close, .order-details-close");
          if (closeBtn) closeBtn.addEventListener("click", () => modal.remove());
        }, 100);
        document.body.appendChild(modal);
      });
  }

  // --- Live Orders pagination state ---
  let LIVE_ORDERS_PER_PAGE = 8;
  let liveOrdersState = {
    page: 1,
    perPage: LIVE_ORDERS_PER_PAGE,
    status: '',
    location: ''
  };

  function renderLiveOrdersPagination(totalPages, currentPage) {
    const pagEl = document.getElementById('live-orders-pagination');
    if (!pagEl) return;
    pagEl.innerHTML = '';
    // Always render the pagination bar, even for a single page
    const pages = Math.max(1, parseInt(totalPages || 0, 10));
    currentPage = Math.max(1, parseInt(currentPage || 1, 10));

    const makeBtn = (label, page, disabled = false, active = false) => {
      const btn = document.createElement('button');
      btn.className = 'pager-btn' + (active ? ' active' : '');
      btn.type = 'button';
      btn.textContent = label;
      btn.disabled = !!disabled;
      if (!disabled) btn.dataset.page = String(page);
      btn.style.padding = '6px 10px';
      btn.style.border = '1px solid #e5e7eb';
      btn.style.borderRadius = '6px';
      return btn;
    };

  // Prev
  pagEl.appendChild(makeBtn('Prev', Math.max(1, currentPage - 1), currentPage <= 1, false));

    // Windowed numeric buttons (max 7)
    const windowSize = 7;
    const half = Math.floor(windowSize / 2);
    let start = Math.max(1, currentPage - half);
  let end = Math.min(pages, start + windowSize - 1);
    start = Math.max(1, end - windowSize + 1);
    for (let p = start; p <= end; p++) {
      pagEl.appendChild(makeBtn(String(p), p, false, p === currentPage));
    }

  // Next
  pagEl.appendChild(makeBtn('Next', Math.min(pages, currentPage + 1), currentPage >= pages, false));

    // Clicks
    pagEl.onclick = (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) return;
      const p = target.dataset.page ? parseInt(target.dataset.page, 10) : NaN;
      if (!isNaN(p) && p !== liveOrdersState.page) {
        liveOrdersState.page = p;
        fetchOrders(liveOrdersState.status, p);
      }
    };
  }

  function fetchOrders(status = '', page = null) {
    // Use the same container your initial render uses (do not change wrapper)
    const listContainer = document.querySelector('.live-orders-grid'); 
    if (!listContainer) {
      console.warn('[admin] live-orders container not found');
      return;
    }
    const locSel = document.getElementById('live-location-filter');
    const location = locSel ? locSel.value : '';
    // Update state
    if (typeof status === 'string') liveOrdersState.status = status;
    liveOrdersState.location = location;
    if (page !== null) liveOrdersState.page = page;
    const qEl = document.getElementById('live-name-search');
    let q = qEl ? (qEl.value || '').trim() : '';
    if (!q) {
      const params = new URLSearchParams(window.location.search);
      q = (params.get('q') || '').trim();
    }
    const url = 'AJAX/fetch_live_orders.php?status=' + encodeURIComponent(liveOrdersState.status)
      + (location ? ('&location=' + encodeURIComponent(location)) : '')
      + (q ? ('&q=' + encodeURIComponent(q)) : '')
      + '&page=' + encodeURIComponent(liveOrdersState.page)
      + '&perPage=' + encodeURIComponent(liveOrdersState.perPage)
      + '&_ts=' + Date.now(); // cache-bust
  fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Debug': '1' }, cache: 'no-store' })
      .then(async res => {
        // Read pagination headers before consuming body
        const totalPages = parseInt(res.headers.get('X-Total-Pages') || '0', 10) || 0;
        const currentPage = parseInt(res.headers.get('X-Page') || String(liveOrdersState.page), 10) || liveOrdersState.page;
        // If our current page is now out of range (e.g., after an item moved/cancelled), refetch the last valid page
        if (totalPages >= 1 && currentPage > totalPages) {
          liveOrdersState.page = totalPages;
          // Trigger a corrected fetch and short-circuit this pipeline
          fetchOrders(liveOrdersState.status, totalPages);
          // Return a noop object to stop downstream update
          return { html: null, totalPages, currentPage: totalPages, _skip: true };
        }
        const html = await res.text();
        return { html, totalPages, currentPage };
      })
      .then(({ html, totalPages, currentPage, _skip }) => {
        if (_skip) return; // already re-fetching with corrected page
        // Replace only the inner content so layout styles remain
        listContainer.innerHTML = html;
        // Delegated click handler covers new buttons
        renderLiveOrdersPagination(totalPages, currentPage);
      })
      .catch(err => console.error('[admin] fetchOrders error', err));
  }

  // Keep compatibility function name but wire nothing (events are delegated globally)
  function attachOrderActionHandlers() {
    const map = new Map([
      ['.btn-accept', 'preparing'],
      ['.btn-ready', 'ready'],
      ['.btn-complete', 'picked up'],
      ['.btn-reject', 'cancelled'],
    ]);

    map.forEach((status, selector) => {
      document.querySelectorAll(selector).forEach(btn => {
        if (btn._boundByMainJs) return;
        btn._boundByMainJs = true;
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const id = getOrderIdFrom(btn);
          if (!id) {
            console.error('[admin] Unable to determine order ID for action', selector);
            return;
          }
          const prevDisabled = btn.disabled;
          btn.disabled = true;
          updateOrderStatus(id, status)
            .finally(() => { btn.disabled = prevDisabled; });
        }, { capture: true });
      });
    });
  }



  function updateOrderStatus(orderId, status) {
    return fetch('update_order_status.php', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Debug': '1'
      },
      credentials: 'same-origin',
      body: 'id=' + encodeURIComponent(orderId) + '&status=' + encodeURIComponent(status)
    })
      .then(res => {
        console.debug('[admin] updateOrderStatus response status', res.status);
        if (res.status === 403) {
          return res.json().then(d => { throw new Error(d && d.message ? d.message : 'Forbidden'); });
        }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(data => {
        console.debug('[admin] updateOrderStatus response body', data);
        if (!data || !data.success) throw new Error((data && data.message) || 'Update failed');
        const activeTab = document.querySelector('#live-orders-tabs .tab.active');
        const activeStatus = activeTab ? (activeTab.dataset.status || '') : '';
        // Optimistically remove the card from Pending tab to avoid stale display while refresh happens
        try {
          if (activeStatus === 'pending' && status === 'preparing') {
            const stale = document.querySelector(`.live-orders-grid [data-transac-id="${CSS.escape(String(orderId))}"]`);
            if (stale && stale.parentElement) stale.parentElement.removeChild(stale);
          }
        } catch (_) { /* ignore */ }
        fetchOrders(activeStatus);
        return data;
      })
      .catch(err => {
        console.error('[admin] updateOrderStatus error', err);
        alert(err && err.message ? err.message : 'Failed to update order status.');
        throw err;
      });
  }

  if (document.getElementById('live-orders-list')) {
    attachOrderActionHandlers();
  }
  if (document.querySelector('.live-orders-grid')) {
    attachOrderActionHandlers();
  }

  // --- Dashboard Stats Live Update ---
  function updateDashboardStats() {
    fetch('AJAX/dashboard_stats.php')
      .then(res => res.json())
      .then(stats => {
        const elTotal = document.getElementById('stat-total-orders');
        const elPending = document.getElementById('stat-pending-orders');
        const elPrep = document.getElementById('stat-preparing-orders');
        const elReady = document.getElementById('stat-ready-orders');
        if (elTotal) elTotal.textContent = stats.totalOrdersToday ?? '0';
        if (elPending) elPending.textContent = stats.pendingOrders ?? '0';
        if (elPrep) elPrep.textContent = stats.preparingOrders ?? '0';
        if (elReady) elReady.textContent = stats.readyOrders ?? '0';
      })
      .catch(err => console.error('[admin] updateDashboardStats error', err));
  }
  updateDashboardStats();
  setInterval(updateDashboardStats, 15000);

  // Promo upload: confirm + AJAX (no reload)
  const promoUploadForm = document.querySelector("form[action='upload_promo.php']");
  if (promoUploadForm) {
    promoUploadForm.addEventListener('submit', async function (e) {
      const titleInput = promoUploadForm.querySelector("input[name='title']");
      const fileInput = promoUploadForm.querySelector("input[name='promoImage']");
      const title = titleInput ? (titleInput.value || '').trim() : '';
      const file = (fileInput && fileInput.files && fileInput.files[0]) ? fileInput.files[0] : null;

      // Require a file client-side too
      if (!file) {
        // let the native validation handle if attribute required; just guard
        return;
      }

      // Confirmation prompt
      const lines = ['Add this promo?'];
      if (title) lines.push('Title: ' + title);
      lines.push('File: ' + file.name);
      lines.push('', 'Proceed to upload?');
      const confirmed = window.confirm(lines.join('\n'));
      if (!confirmed) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      // Submit via fetch to avoid page reload
      e.preventDefault();
      e.stopPropagation();
      try {
        const formData = new FormData(promoUploadForm);
        const res = await fetch('upload_promo.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          body: formData
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.success) {
          const msg = (data && data.message) ? data.message : (`Upload failed (${res.status})`);
          alert(msg);
          return;
        }
        // Append new card to promos grid
        const grid = document.getElementById('promos-grid');
        if (grid) {
          const pid = data.promo_id;
          const safeTitle = escapeHtml((data.title || '').toString());
          const card = document.createElement('div');
          card.className = 'promo-card';
          card.dataset.promoId = String(pid);
          card.dataset.active = '1';
          const today = new Date();
          const y = today.getFullYear();
          const m = String(today.getMonth()+1).padStart(2,'0');
          const d = String(today.getDate()).padStart(2,'0');
          const dateLabel = `${y}-${m}-${d}`;
          card.innerHTML =
            `<div class="promo-thumb">
               <img class="promo-thumb-img" src="serve_promo.php?promo_id=${pid}" alt="${safeTitle}" loading="lazy">
             </div>
             <div class="promo-title">${safeTitle}</div>
             <div class="promo-date">${dateLabel}</div>
             <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px;">
               <span class="status-badge active promo-status-badge">Active</span>
               <div style="display:flex;gap:6px;">
                 <form class="promo-toggle-form" method="post" action="update_promos.php" style="margin:0;">
                   <input type="hidden" name="promo_id" value="${pid}">
                   <input type="hidden" name="active" value="0">
                   <button type="submit" class="btn-primary promo-toggle-btn" style="padding:6px 10px;">Set Inactive</button>
                 </form>
                 <form class="promo-delete-form" method="post" action="delete_promo.php" style="margin:0;">
                   <input type="hidden" name="id" value="${pid}">
                   <button type="submit" class="btn-secondary promo-delete-btn" style="padding:6px 10px;">Delete</button>
                 </form>
               </div>
             </div>`;
          grid.prepend(card);
        }
        // Reset form after successful upload
        promoUploadForm.reset();
        showNotification('Promo uploaded');
      } catch (err) {
        console.error('[admin] promo upload error', err);
        alert('Upload error: ' + (err && err.message ? err.message : 'Unknown error'));
      }
    });
  }

  // Promos: toggle active/inactive and delete without reload (AJAX)
  (function promosInit() {
    const grid = document.getElementById('promos-grid');
    if (!grid) return;

    grid.addEventListener('submit', async (e) => {
      const form = e.target.closest('.promo-toggle-form, .promo-delete-form');
      if (!form) return;
      e.preventDefault();
      e.stopPropagation();

      const card = form.closest('.promo-card');
      if (!card) return;
      const promoId = card.dataset.promoId || form.querySelector('input[name="promo_id"], input[name="id"]').value;
      if (!promoId) return;

      // Toggle status
      if (form.classList.contains('promo-toggle-form')) {
        const currentlyActive = (card.dataset.active === '1');
        const next = currentlyActive ? 0 : 1;
        const confirmMsg = next === 0
          ? 'This promo will be inactive and hidden from customers. Continue?'
          : 'This promo will be visible to customers. Continue?';
        if (!window.confirm(confirmMsg)) return;

        try {
          const fd = new FormData();
          fd.append('promo_id', String(promoId));
          fd.append('active', String(next));
          const res = await fetch('update_promos.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
          });
          const data = await res.json().catch(() => null);
          if (!res.ok || !data || !data.success) throw new Error(data?.message || `Failed (${res.status})`);

          // Update UI state
          card.dataset.active = String(next);
          const badge = card.querySelector('.promo-status-badge');
          if (badge) {
            badge.textContent = next ? 'Active' : 'Inactive';
            badge.classList.toggle('active', !!next);
            badge.classList.toggle('inactive', !next);
          }
          const toggleBtn = form.querySelector('.promo-toggle-btn');
          if (toggleBtn) toggleBtn.textContent = next ? 'Set Inactive' : 'Set Active';
          const hidden = form.querySelector('input[name="active"]');
          if (hidden) hidden.value = next ? '0' : '1'; // next click will invert
          showNotification(data.message || (next ? 'Promo activated' : 'Promo deactivated'));
        } catch (err) {
          console.error('[admin] promo toggle error', err);
          alert('Failed to update promo status.');
        }
        return;
      }

      // Delete promo
      if (form.classList.contains('promo-delete-form')) {
        if (!window.confirm('This promo image will be permanently deleted. Continue?')) return;
        try {
          const fd = new FormData();
          fd.append('id', String(promoId));
          const res = await fetch('delete_promo.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
          });
          const data = await res.json().catch(() => null);
          if (!res.ok || !data || !data.success) throw new Error(data?.message || `Failed (${res.status})`);
          // Remove card from DOM
          card.remove();
          showNotification(data.message || 'Promo deleted');
        } catch (err) {
          console.error('[admin] promo delete error', err);
          alert('Failed to delete promo.');
        }
        return;
      }
    });
  })();

  // util to escape HTML when injecting strings
  function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

  // --- Price History modal wiring ---
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-price-history');
    if (!btn) return;
    e.preventDefault();
    // Close any open dropdown menu and restore to original parent if floating
    document.querySelectorAll('#products-section .dropdown-menu').forEach(m => {
      if (m && m.style) { m.style.display = 'none'; }
      // Note: restoration handled in admin.php closeAllMenus; this is a best-effort extra guard
    });
    // Find product id from the row or from the button's dataset (menu may be floating)
    const row = btn.closest('tr[data-product-id]');
    let productId = row ? (row.getAttribute('data-product-id') || '').trim() : '';
    if (!productId) {
      productId = (btn.getAttribute('data-product-id') || '').trim();
    }
    if (!productId) { alert('Missing product id.'); return; }
    try {
      const url = new URL('AJAX/fetch_price_history.php', window.location.href);
      url.searchParams.set('product_id', productId);
      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (!res.ok || !data || !data.success) throw new Error(data?.message || `Failed (${res.status})`);
      const list = Array.isArray(data.data) ? data.data : [];
      if (list.length === 0) {
        window.__openPriceHistory('<div style="padding:12px;color:#64748b;">No price history found.</div>');
        return;
      }
      const headers = ['Size / Label','Price (₱)','Effective From','Effective To'];
      const th = headers.map(h => `<th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">${escapeHtml(h)}</th>`).join('');
      const rows = list.map(r => {
        const sz = r.size_label || '';
        const price = Number(r.price || 0).toFixed(2);
        const from = r.effective_from || '';
        const to = r.effective_to || '';
        return `<tr>`+
          `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">${escapeHtml(sz)}</td>`+
          `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">₱${escapeHtml(price)}</td>`+
          `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">${escapeHtml(from)}</td>`+
          `<td style="padding:8px;border-bottom:1px solid #f3f4f6;">${escapeHtml(to || '—')}</td>`+
        `</tr>`;
      }).join('');
      const html = `<table style="width:100%;border-collapse:collapse;font-size:14px;">`+
        `<thead><tr>${th}</tr></thead>`+
        `<tbody>${rows}</tbody>`+
      `</table>`;
      window.__openPriceHistory(html);
    } catch (err) {
      console.error('[admin] price history error', err);
      window.__openPriceHistory('<div style="padding:12px;color:#dc2626;">Failed to load history.</div>');
    }
  }, true);

  // --- Customers (block/unblock) ---
  (function customersInit() {
    const tbody = document.getElementById('customers-tbody');
    if (!tbody) return; // section not on page
    const search = document.getElementById('customers-search');
    const refreshBtn = document.getElementById('refresh-customers');
    const pager = document.getElementById('customers-pagination');

    // Pagination state
    const state = { page: 1, perPage: 10, q: '' };
    let lastResponse = { users: [], total: 0, page: 1, perPage: 10, totalPages: 1 };

    function render(list) {
      tbody.innerHTML = '';
      if (!list || list.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 5;
        td.style.textAlign = 'center';
        td.textContent = 'No users found.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
      }
      list.forEach(u => {
        const tr = document.createElement('tr');
        const name = `${u.user_FN || ''} ${u.user_LN || ''}`.trim();
        const blocked = !!(u.is_blocked === 1 || u.is_blocked === '1' || u.is_blocked === true);
        tr.innerHTML =
          `<td>${u.user_id}</td>` +
          `<td>${escapeHtml(name)}</td>` +
          `<td>${escapeHtml(u.user_email || '')}</td>` +
          `<td style="text-align:center;">` +
            `<span class="status-badge ${blocked ? 'inactive' : 'active'}">${blocked ? 'Blocked' : 'Active'}</span>` +
          `</td>` +
          `<td style="text-align:center;">` +
            `<button type="button" class="btn-${blocked ? 'primary' : 'secondary'} btn-toggle-block" data-id="${u.user_id}" data-block="${blocked ? '0' : '1'}">` +
              `${blocked ? 'Unblock' : 'Block'}` +
            `</button>` +
          `</td>`;
        tbody.appendChild(tr);
      });
    }

    function renderPager(meta) {
      if (!pager) return;
      pager.innerHTML = '';
      const totalPages = meta.totalPages || 1;
      const current = meta.page || 1;
      if (totalPages <= 1) return;
      const mk = (label, page, disabled = false, active = false) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.disabled = !!disabled;
        b.dataset.page = String(page);
        b.className = 'pager-btn' + (active ? ' active' : '');
        b.style.cssText = 'padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;';
        if (!disabled && !active) b.addEventListener('click', () => load(page));
        return b;
      };
      pager.appendChild(mk('Prev', Math.max(1, current - 1), current <= 1));
      // show up to 7 windowed numbers
      const windowSize = 8;
      let start = Math.max(1, current - Math.floor(windowSize / 2));
      let end = Math.min(totalPages, start + windowSize - 1);
      start = Math.max(1, end - windowSize + 1);
      for (let p = start; p <= end; p++) pager.appendChild(mk(String(p), p, false, p === current));
      pager.appendChild(mk('Next', Math.min(totalPages, current + 1), current >= totalPages));
    }

    async function load(page = null) {
      if (page != null) state.page = page;
      // keep q from input
      state.q = (search?.value || '').trim();
      try {
        const url = new URL('AJAX/customers.php', window.location.href);
        url.searchParams.set('action', 'list');
        url.searchParams.set('page', String(state.page));
        url.searchParams.set('perPage', String(state.perPage));
        if (state.q) url.searchParams.set('q', state.q);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok || !data || !data.success) throw new Error(data?.message || `Failed (${res.status})`);
        lastResponse = { users: data.users || [], total: data.total || 0, page: data.page || 1, perPage: data.perPage || state.perPage, totalPages: data.totalPages || 1 };
        render(lastResponse.users);
        renderPager(lastResponse);
      } catch (err) {
        console.error('[admin] customers load error', err);
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#dc2626;">Failed to load users</td></tr>';
        if (pager) pager.innerHTML = '';
      }
    }
    // Search will reload from page 1
    function applyFilter() { load(1); }

    tbody.addEventListener('click', async (e) => {
      const btn = e.target.closest('.btn-toggle-block');
      if (!btn) return;
      const id = parseInt(btn.dataset.id || '0', 10) || 0;
      const block = btn.dataset.block === '1';
      if (!id) return;
      const confirmMsg = block ? 'Block this user from ordering?' : 'Unblock this user?';
      if (!window.confirm(confirmMsg)) return;
      try {
        const fd = new FormData();
        fd.append('action', block ? 'block' : 'unblock');
        fd.append('user_id', String(id));
        const res = await fetch('AJAX/customers.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok || !data || !data.success) throw new Error(data?.message || `Failed (${res.status})`);
        // After toggle, reload current page to reflect changes
        load(state.page);
        showNotification(block ? 'User blocked' : 'User unblocked');
      } catch (err) {
        console.error('[admin] toggle block error', err);
        alert('Failed to update user status.');
      }
    });

    if (search) search.addEventListener('input', () => applyFilter());
    if (refreshBtn) refreshBtn.addEventListener('click', () => load(1));
    load(1);
  })();
});

function getOrderIdFrom(el) {
  if (!el) return null;
  if (el.dataset && el.dataset.id) return el.dataset.id;
  const form = el.closest('form');
  if (form) {
    const inp = form.querySelector('input[name="id"], input[name="transac_id"], input[name="order_id"]');
    if (inp && inp.value) return inp.value;
  }
  const container = el.closest('[data-transac-id], [data-id], .order-card');
  if (container) {
    if (container.dataset && container.dataset.transacId) return container.dataset.transacId;
    if (container.dataset && container.dataset.id) return container.dataset.id;
  }
  return null;
}
