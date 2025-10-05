console.info('[admin] main.js loaded');

document.addEventListener("DOMContentLoaded", () => {
  console.info('[admin] DOMContentLoaded fired');
  // Products pagination page size (must be defined before initial apply)
  const PRODUCTS_PER_PAGE = 5;

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
    }
    document.querySelectorAll(".nav-item").forEach(item => {
      item.classList.remove("active");
      let itemSection = item.getAttribute("data-section");
      if (itemSection === "dashboard") itemSection = "dashboard-overview";
      if (itemSection === sectionName) item.classList.add("active");
    });
  }


  // Sidebar Navigation
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
    return rows.filter(tr => {
      const type = (tr.getAttribute('data-product-type') || '').toLowerCase();
      return filter === 'all' || type === filter;
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
    Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
      if (rows.includes(tr)) {
        tr.style.display = set.has(tr) ? '' : 'none';
      } else {
        tr.style.display = 'none';
      }
    });

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

  // Update URL without navigating, preserving location filter
  const locSel = document.getElementById('live-location-filter');
  const location = locSel ? locSel.value : '';
  const params = new URLSearchParams();
  params.set('status', status);
  if (location) params.set('location', location);
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
      const tAllowedTypes = document.getElementById('toppingAllowedTypes');
      const tAllowedCats = document.getElementById('toppingAllowedCategories');
      if (tAllowedTypes) tAllowedTypes.value = '';
      if (tAllowedCats) tAllowedCats.value = '';
      if (addModal) addModal.style.display = 'flex';
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', () => { if (addModal) addModal.style.display = 'none'; });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { if (addModal) addModal.style.display = 'none'; });

  // delegated actions: edit / toggle / delete
  document.body.addEventListener('click', async function (e) {
    const target = e.target;

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
  const allowedTypes = (document.getElementById('toppingAllowedTypes')?.value || '').trim();
  const allowedCats = (document.getElementById('toppingAllowedCategories')?.value || '').trim();
      if (!name) { if (resultEl) resultEl.textContent = 'Name required'; return; }
      const body = new URLSearchParams();
      body.append('name', name);
      body.append('price', price);
  if (allowedTypes) body.append('allowed_types', allowedTypes);
  if (allowedCats) body.append('allowed_categories', allowedCats);
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

  // Toast
  function showNotification(message) {
    const notification = document.createElement("div");
    notification.className = "notification";
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => notification.classList.add("show"), 10);
    setTimeout(() => {
      notification.classList.remove("show");
      setTimeout(() => notification.remove(), 300);
    }, 3000);
  }



  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-accept, .btn-ready, .btn-complete, .btn-reject');
    if (!btn) return;
    // Prevent any form submission or inline handlers
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

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

    const prev = btn.disabled;
    btn.disabled = true;
    updateOrderStatus(orderId, nextStatus)
      .finally(() => { btn.disabled = prev; });
  }, true); // capture to block inline onclicks if any

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
    const listContainer = document.querySelector('.live-orders-grid'); // or '#live-orders-list' if that is your inner list
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
    const url = 'AJAX/fetch_live_orders.php?status=' + encodeURIComponent(liveOrdersState.status)
      + (location ? ('&location=' + encodeURIComponent(location)) : '')
      + '&page=' + encodeURIComponent(liveOrdersState.page)
      + '&perPage=' + encodeURIComponent(liveOrdersState.perPage);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(async res => {
        // Read pagination headers before consuming body
        const totalPages = parseInt(res.headers.get('X-Total-Pages') || '0', 10) || 0;
        const currentPage = parseInt(res.headers.get('X-Page') || String(liveOrdersState.page), 10) || liveOrdersState.page;
        const html = await res.text();
        return { html, totalPages, currentPage };
      })
      .then(({ html, totalPages, currentPage }) => {
        // Replace only the inner content so layout styles remain
        listContainer.innerHTML = html;
        // Delegated click handler covers new buttons
        renderLiveOrdersPagination(totalPages, currentPage);
      })
      .catch(err => console.error('[admin] fetchOrders error', err));
  }

  // Keep a no-op to avoid errors where older code calls this
  function attachOrderActionHandlers() {
    // Events are delegated globally; nothing to do here
  }


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
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: 'id=' + encodeURIComponent(orderId) + '&status=' + encodeURIComponent(status)
    })
      .then(res => {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(data => {
        if (!data || !data.success) throw new Error((data && data.message) || 'Update failed');
        const activeTab = document.querySelector('#live-orders-tabs .tab.active');
        const activeStatus = activeTab ? (activeTab.dataset.status || '') : '';
        fetchOrders(activeStatus);
        return data;
      })
      .catch(err => {
        console.error('[admin] updateOrderStatus error', err);
        alert('Failed to update order status.');
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
          const imgSrc = '/' + (data.image || '');
          const safeTitle = (data.title || '').toString();
          const wrapper = document.createElement('div');
          wrapper.style.width = '200px';
          wrapper.style.border = '1px solid #eefaf0';
          wrapper.style.padding = '8px';
          wrapper.style.borderRadius = '8px';
          wrapper.style.background = '#fff';
          wrapper.innerHTML = ""
            + `<img src="${imgSrc}" style="width:100%;height:120px;object-fit:cover;border-radius:6px;margin-bottom:8px;">`
            + `<div style="font-size:0.9rem;font-weight:600;margin-bottom:6px;">${escapeHtml(safeTitle)}</div>`
            + `<div style="display:flex;gap:6px;">`
            + `  <form method="post" action="delete_promo.php" style="margin:0;">`
            + `    <input type="hidden" name="id" value="${data.promo_id}">`
            + `    <button class="btn-secondary" type="submit" style="padding:6px 8px;">Delete</button>`
            + `  </form>`
            + `  <form method="post" action="update_promos.php" style="margin:0;">`
            + `    <input type="hidden" name="id" value="${data.promo_id}">`
            + `    <input type="hidden" name="active" value="0">`
            + `    <button class="btn-primary" type="submit" style="padding:6px 8px;">Set Inactive</button>`
            + `  </form>`
            + `</div>`;
          grid.prepend(wrapper);
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

  // util to escape HTML when injecting strings
  function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

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
      const windowSize = 7;
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
