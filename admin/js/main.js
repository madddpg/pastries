console.info('[admin] main.js loaded');

document.addEventListener("DOMContentLoaded", () => {
  console.info('[admin] DOMContentLoaded fired');

  // Track current section safely
  let currentSection = null;

  // Section switching logic (adjusted for your HTML IDs)
  function showSection(sectionName) {
    // Map "dashboard" to your real section id "dashboard-overview"
    if (sectionName === "dashboard") sectionName = "dashboard-overview";

    // Hide all, show the target
    document.querySelectorAll(".content-section").forEach((section) => {
      section.classList.remove("active");
    });

    const targetSection = document.getElementById(`${sectionName}-section`);
    if (targetSection) {
      targetSection.classList.add("active");
      currentSection = sectionName; // keep track
    } else {
      console.warn(`[admin] Section #${sectionName}-section not found`);
    }

    // Update sidebar active state
    document.querySelectorAll(".nav-item").forEach((item) => {
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

  // Detect ?status=... and show Live Orders section
  function getUrlParameter(name) {
    name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
    const regex = new RegExp("[\\?&]" + name + "=([^&#]*)");
    const results = regex.exec(location.search);
    return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
  }

  if (getUrlParameter("status") !== "" || window.location.search.indexOf("status=") !== -1) {
    showSection("live-orders");
    const status = getUrlParameter("status");
    document.querySelectorAll("#live-orders-tabs .tab").forEach((tab) => {
      if ((tab.dataset.status || "") === status) tab.classList.add("active");
      else tab.classList.remove("active");
    });
    document.querySelectorAll(".nav-item").forEach((item) => {
      if (item.getAttribute("data-section") === "live-orders") item.classList.add("active");
      else item.classList.remove("active");
    });
  } else {
    showSection("dashboard-overview");
  }

  // Tabs (for tab navigation, not sidebar)
  document.querySelectorAll(".tabs .tab").forEach((tab) => {
    tab.addEventListener("click", function (e) {
      // Skip live-orders tabs (they reload with ?status=)
      if (this.closest("#live-orders-tabs")) return;
      e.preventDefault();
      const tabGroup = this.closest(".tabs");
      if (tabGroup) {
        tabGroup.querySelectorAll(".tab").forEach((t) => t.classList.remove("active"));
      }
      this.classList.add("active");
    });
  });

  // Live Orders Tabs: reload with ?status=...
  const liveOrdersTabs = document.querySelectorAll('#live-orders-tabs .tab');
  liveOrdersTabs.forEach(tab => {
    tab.addEventListener('click', function(e) {
      e.preventDefault();
      liveOrdersTabs.forEach(t => t.classList.remove('active'));
      this.classList.add('active');
      const status = this.dataset.status;
      window.location.search = status ? ('?status=' + encodeURIComponent(status)) : '';
    });
  });

  // Action Buttons (dropdown menu)
  document.querySelectorAll(".action-btn").forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      document.querySelectorAll(".action-menu .dropdown-menu").forEach((menu) => {
        menu.style.display = "none";
      });
      const menu = this.closest(".action-menu")?.querySelector(".dropdown-menu");
      if (menu) menu.style.display = menu.style.display === "block" ? "none" : "block";
    });
  });

  document.addEventListener('click', function() {
    document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
      menu.style.display = "none";
    });
  });

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
(function(){
    // Correct API path relative to admin.php
    const API = 'AJAX/get_toppings.php';

async function fetchToppings() {
    try {
        const res = await fetch(`${API}?action=list`);
        const text = await res.text(); // get raw response to inspect HTML/errors
        // show raw response in console when not valid JSON
        try {
            const data = JSON.parse(text);
            if (!data.success) return;
            const tbody = document.querySelector('#toppingsTable tbody');
            if (!tbody) return;
            tbody.innerHTML = data.toppings.map(t => `
                <tr data-id="${t.id}" data-status="${t.status}">
                    <td style="width:60px;">${t.id}</td>
                    <td>${t.name}</td>
                    <td style="text-align:right;">₱${Number(t.price).toFixed(2)}</td>
                    <td style="text-align:center;">
                        <span class="status-badge ${t.status === 'active' ? 'active' : 'inactive'}">${t.status === 'active' ? 'Active' : 'Inactive'}</span>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <button class="btn-edit-topping btn-edit-topping-sm" data-id="${t.id}">Edit</button>
                        <button class="btn-toggle-topping btn-toggle-topping-sm" data-id="${t.id}" data-status="${t.status}">${t.status === 'active' ? 'Set Inactive' : 'Set Active'}</button>
                        <button class="btn-delete-topping btn-delete-topping-sm" data-id="${t.id}">Delete</button>
                    </td>
                </tr>
            `).join('');
        } catch (parseErr) {
            console.error('fetchToppings: response is not JSON — raw response below:');
            console.log(text);
            console.error('JSON parse error:', parseErr);
            // optionally show a small UI hint
            const tbody = document.querySelector('#toppingsTable tbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="color:#c0392b">Server error: check console/network for response</td></tr>';
        }
    } catch (err) {
        console.error('fetchToppings error', err);
    }
}


async function loadActiveToppings() {
  try {
    const res = await fetch('admin/AJAX/get_toppings.php?action=active', { cache: 'no-store' });
    const data = await res.json();
    if (!data.success || !Array.isArray(data.toppings)) return;
    const container = document.getElementById('toppingsList');
    if (!container) return;
    // render each topping as checkbox; use topping id as data-id and price as data-price
    container.innerHTML = data.toppings.map(t => {
      const safeName = (t.name || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      return `<label style="display:block;margin-bottom:6px;"><input type="checkbox" class="topping-checkbox" data-id="${t.id}" data-price="${Number(t.price).toFixed(2)}"> ${safeName} — ₱${Number(t.price).toFixed(2)}</label>`;
    }).join('');
    bindToppingCheckboxes(); // attach onchange handlers
    recalcModalTotal(); // update displayed total
  } catch (err) {
    console.error('loadActiveToppings error', err);
  }
}

function bindToppingCheckboxes() {
  document.querySelectorAll('.topping-checkbox').forEach(cb => {
    // remove any previous listener to avoid duplicates
    cb.onchange = null;
    cb.onchange = function () {
      const key = cb.getAttribute('data-id') || cb.getAttribute('data-key') || cb.value;
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

// Run once on page load so product modal toppings reflect DB status
document.addEventListener('DOMContentLoaded', function () {
  loadActiveToppings();
});


    // Run immediately (outer DOMContentLoaded already handled)
    fetchToppings();

    // UI handlers (no inner DOMContentLoaded wrapper)
    const showBtn = document.getElementById('showAddToppingModalBtn');
    const addModal = document.getElementById('addToppingModal');
    const closeBtn = document.getElementById('closeAddToppingModal');
    const cancelBtn = document.getElementById('cancelToppingBtn');
    const form = document.getElementById('toppingForm');
    const resultEl = document.getElementById('toppingFormResult');

    if (showBtn) {
        showBtn.addEventListener('click', function(){
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
    document.body.addEventListener('click', async function(e){
        const target = e.target;

        if (target.matches('.btn-edit-topping')) {
            const id = target.dataset.id;
            const row = document.querySelector(`#toppingsTable tr[data-id="${id}"]`);
            if (!row) return;
            document.getElementById('addToppingTitle').textContent = 'Edit Topping';
            document.getElementById('toppingId').value = id;
            document.getElementById('toppingName').value = row.children[1].textContent;
            document.getElementById('toppingPrice').value = parseFloat(row.children[2].textContent.replace('₱','')) || 0;
            if (addModal) addModal.style.display = 'flex';
            return;
        }

        if (target.matches('.btn-toggle-topping')) {
            const id = target.dataset.id;
            const current = target.dataset.status === 'active' ? 'active' : 'inactive';
            const next = current === 'active' ? 'inactive' : 'active';
            const body = new URLSearchParams();
            body.append('action','toggle_status');
            body.append('id', id);
            body.append('status', next);
            try {
                const res = await fetch(API, { method: 'POST', body });
                const data = await res.json();
                if (data.success) fetchToppings();
            } catch (err) {
                console.error('toggle topping error', err);
            }
            return;
        }

        if (target.matches('.btn-delete-topping')) {
            const id = target.dataset.id;
            if (!confirm('Delete this topping?')) return;
            const body = new URLSearchParams();
            body.append('action','delete');
            body.append('id', id);
            try {
                const res = await fetch(API, { method: 'POST', body });
                const data = await res.json();
                if (data.success) fetchToppings();
                else alert('Delete failed');
            } catch (err) {
                console.error('delete topping error', err);
            }
            return;
        }
    });

    if (form) {
        form.addEventListener('submit', async function(e){
            e.preventDefault();
            const id = document.getElementById('toppingId').value;
            const name = document.getElementById('toppingName').value.trim();
            const price = document.getElementById('toppingPrice').value;
            if (!name) { if (resultEl) resultEl.textContent = 'Name required'; return; }
            const body = new URLSearchParams();
            body.append('name', name);
            body.append('price', price);
            if (!id) {
                body.append('action','add');
            } else {
                body.append('action','update');
                body.append('id', id);
            }
            try {
                const res = await fetch(API, { method: 'POST', body });
                const data = await res.json();
                if (data.success) {
                    if (addModal) addModal.style.display = 'none';
                    fetchToppings();
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

  // --- Live Orders AJAX ---
  function fetchOrders(status = '') {
    const listContainer = document.getElementById('live-orders-list');
    if (!listContainer) {
      console.warn('[admin] #live-orders-list container not found in HTML');
      return;
    }
    fetch('../api/get_orders.php?status=' + encodeURIComponent(status))
      .then(res => res.text())
      .then(html => {
        listContainer.innerHTML = html;
        attachOrderActionHandlers();
      })
      .catch(err => console.error('[admin] fetchOrders error', err));
  }

  function attachOrderActionHandlers() {
    document.querySelectorAll('.btn-accept').forEach(btn => {
      btn.onclick = () => updateOrderStatus(btn.dataset.id, 'preparing');
    });
    document.querySelectorAll('.btn-ready').forEach(btn => {
      btn.onclick = () => updateOrderStatus(btn.dataset.id, 'ready');
    });
    document.querySelectorAll('.btn-complete').forEach(btn => {
      btn.onclick = () => updateOrderStatus(btn.dataset.id, 'completed');
    });
    document.querySelectorAll('.btn-reject').forEach(btn => {
      btn.onclick = () => updateOrderStatus(btn.dataset.id, 'cancelled');
    });
  }

  function updateOrderStatus(orderId, status) {
    fetch('updating/update_order_status.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(orderId) + '&status=' + encodeURIComponent(status)
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const activeTab = document.querySelector('#live-orders-tabs .tab.active');
        const st = activeTab ? activeTab.dataset.status : '';
        fetchOrders(st);
      } else {
        alert('Failed to update order');
      }
    })
    .catch(err => console.error('[admin] updateOrderStatus error', err));
  }

  // Initial hookup for live orders (only if container exists)
  if (document.getElementById('live-orders-list')) {
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
        if (elTotal)   elTotal.textContent = stats.totalOrdersToday ?? '0';
        if (elPending) elPending.textContent = stats.pendingOrders ?? '0';
        if (elPrep)    elPrep.textContent = stats.preparingOrders ?? '0';
        if (elReady)   elReady.textContent = stats.readyOrders ?? '0';
      })
      .catch(err => console.error('[admin] updateDashboardStats error', err));
  }
  updateDashboardStats();
  setInterval(updateDashboardStats, 15000);
});
