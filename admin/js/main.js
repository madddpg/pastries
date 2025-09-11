console.info('[admin] main.js loaded');

document.addEventListener("DOMContentLoaded", () => {
  console.info('[admin] DOMContentLoaded fired');

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
      const tabGroup = this.closest(".tabs");
      if (tabGroup) {
        tabGroup.querySelectorAll(".tab").forEach((t) => t.classList.remove("active"));
      }
      this.classList.add("active");
    });
  });

 // Live Orders Tabs: SPA behavior (no page reload)
  const liveOrdersTabs = document.querySelectorAll('#live-orders-tabs .tab');
  liveOrdersTabs.forEach(tab => {
    // Use capture to prevent earlier handlers (from inline scripts) from firing
    tab.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      // Activate selected tab
      liveOrdersTabs.forEach(t => t.classList.remove('active'));
      this.classList.add('active');

      // Show section and load orders
      const status = this.dataset.status || '';
      showSection('live-orders');
      try { fetchOrders(status); } catch (e) { /* fetchOrders defined later */ }

      // Update URL without navigating
      const newUrl = status ? ('?status=' + encodeURIComponent(status)) : '?status=';
      history.replaceState(null, '', newUrl);
    }, true); // capture phase
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
            return String(html || '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
        }

        tbody.innerHTML = data.toppings.map(t => {
            const status = (t.status === 'active') ? 'active' : 'inactive';
            const editBtn = `<button class="btn-edit-topping edit-topping-btn" data-id="${t.id}" data-name="${esc(t.name)}" data-price="${esc(t.price)}">Edit</button>`;
            const toggleBtn = `<button class="btn-toggle-topping toggle-topping-status" data-id="${t.id}" data-status="${status}" style="margin-left:8px;">${status === 'active' ? 'Set Inactive' : 'Set Active'}</button>`;
            // only normal delete for super admins (no force delete)
            const deleteBtn = isSuper ? `<button class="btn-delete-topping topping-delete" data-id="${t.id}" style="margin-left:8px;color:#ef4444;">Delete</button>` : '';

            return `
                <tr data-id="${t.id}" data-status="${status}">
                    <td style="width:60px;">${t.id}</td>
                    <td>${esc(t.name)}</td>
                    <td style="text-align:right;">₱${Number(t.price).toFixed(2)}</td>
                    <td style="text-align:center;"><span class="status-badge ${status}">${status.charAt(0).toUpperCase() + status.slice(1)}</span></td>
                    <td style="text-align:center;white-space:nowrap;">
                        ${editBtn}
                        ${toggleBtn}
                        ${deleteBtn}
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('fetchToppings error', err);
        const tbody = document.querySelector('#toppingsTable tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="color:#c0392b">Server error: check console</td></tr>';
    }
}

async function loadActiveToppings() {
  try {
    const res = await fetch('admin/AJAX/get_toppings.php?action=active', { cache: 'no-store' });
    const data = await res.json();
    if (!data.success || !Array.isArray(data.toppings)) return;
    const container = document.getElementById('toppingsList');
    if (!container) return;
    container.innerHTML = data.toppings.map(t => {
      const safeName = (t.name || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      return `<label style="display:block;margin-bottom:6px;">
        <input type="checkbox" class="topping-checkbox" data-id="${t.id}" data-price="${Number(t.price).toFixed(2)}">
        ${safeName} — ₱${Number(t.price).toFixed(2)}
      </label>`;
    }).join('');
    bindToppingCheckboxes();
    recalcModalTotal();
  } catch (err) {
    console.error('loadActiveToppings error', err);
  }
}

function bindToppingCheckboxes() {
  document.querySelectorAll('.topping-checkbox').forEach(cb => {
    cb.onchange = function () {
      const key = cb.getAttribute('data-id');
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
    const current = target.dataset.status === 'active' ? 1 : 0;
    const next = current === 1 ? 0 : 1;
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
      if (data.success) {
        fetchToppings();
      } else {
        if (res.status === 409 && data.message && /referenc/i.test(data.message)) {
          if (confirm(data.message + "\n\nMark it INACTIVE instead?")) {
            const body2 = new URLSearchParams();
            body2.append('action','toggle_status');
            body2.append('id', id);
            body2.append('status','0'); // numeric inactive
            const r2 = await fetch(API, { method: 'POST', body: body2 });
            const d2 = await r2.json();
            if (d2.success) fetchToppings();
            else alert('Failed to set inactive: ' + (d2.message || 'unknown'));
          }
        } else {
          alert('Delete failed: ' + (data.message || 'unknown'));
        }
      }
    } catch (err) {
      console.error('delete topping error', err);
      alert('Delete request failed');
    }
    return;
  }

  if (target.matches('.btn-toggle-product')) {
    e.preventDefault();
    const id = target.dataset.id;
    const current = target.dataset.status === 'active' ? 1 : 0;
    const next = current === 1 ? 0 : 1;
    const body = new URLSearchParams();
    body.append('id', id);
    body.append('status', next);
    try {
      const res = await fetch('update_product_status.php', { method: 'POST', body });
      const data = await res.json();
      if (data.success && data.rows > 0) {
        target.dataset.status = next === 1 ? 'active' : 'inactive';
        target.textContent = next === 1 ? 'Set Inactive' : 'Set Active';
        const badge = target.closest('tr')?.querySelector('.status-badge');
        if (badge) {
          badge.classList.toggle('active', next === 1);
          badge.classList.toggle('inactive', next !== 1);
          badge.textContent = next === 1 ? 'Active' : 'Inactive';
        }
      } else {
        alert('Update failed: ' + (data.message || 'no details'));
      }
    } catch (err) {
      console.error('[admin] toggle product error', err);
      alert('Request failed');
    }
    return;
  }
});

// save form
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
    fetchToppings(); // refresh admin table

    // If topping is active, also update checkboxes without reload
    if (data.topping && data.topping.status === 'active') {
        const container = document.getElementById('toppingsList');
        if (container) {
            const t = data.topping;
            const safeName = (t.name || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const html = `<label style="display:block;margin-bottom:6px;">
                <input type="checkbox" class="topping-checkbox" 
                    data-id="${t.id}" data-price="${Number(t.price).toFixed(2)}"> 
                ${safeName} — ₱${Number(t.price).toFixed(2)}
            </label>`;
            container.insertAdjacentHTML('beforeend', html);
            bindToppingCheckboxes(); // rebind new checkbox
        }
    }
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



    document.addEventListener('click', function(e) {
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

  (async function () {

  async function loadToppings() {
    try {
      const res = await fetch('AJAX/get_toppings.php?action=list', { credentials: 'same-origin' });
      const json = await res.json();
      const tbody = document.querySelector('#toppingsTable tbody');
      if (!tbody) return;
      tbody.innerHTML = '';
      const isSuper = !!json.is_super;
      json.toppings.forEach(t => {
        const statusLabel = t.status === 'active' ? 'active' : 'inactive';
        const actionBtns = [];
        actionBtns.push(`<button class="edit-topping-btn" data-id="${t.id}" data-name="${esc(t.name)}" data-price="${esc(t.price)}" style="margin-right:8px;">Edit</button>`);
        actionBtns.push(`<button class="toggle-topping-status" data-id="${t.id}" data-status="${statusLabel}" style="margin-right:8px;">Set ${statusLabel === 'active' ? 'Inactive' : 'Active'}</button>`);
        if (isSuper) {
          actionBtns.push(`<button class="topping-delete" data-id="${t.id}" style="color:#ef4444;margin-right:8px;">Delete</button>`);
        }
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${t.id}</td>
          <td>${esc(t.name)}</td>
          <td style="text-align:right;">₱${parseFloat(t.price).toFixed(2)}</td>
          <td style="text-align:center;"><span class="status-badge ${statusLabel}">${statusLabel}</span></td>
          <td style="text-align:center;">${actionBtns.join('')}</td>
        `;
        tbody.appendChild(tr);
      });
    } catch (err) {
      console.error('Failed to load toppings', err);
    }
  }

  // delegated handlers
  document.addEventListener('click', async function (e) {
    const btn = e.target;

    // Edit
    if (btn.matches('.edit-topping-btn')) {
      e.preventDefault();
      const id = btn.dataset.id;
      document.getElementById('toppingId').value = id;
      document.getElementById('toppingName').value = btn.dataset.name || '';
      document.getElementById('toppingPrice').value = btn.dataset.price || '';
      document.getElementById('addToppingModal').style.display = 'flex';
      document.getElementById('addToppingTitle').textContent = 'Edit Topping';
      return;
    }

    // Toggle status
    if (btn.matches('.toggle-topping-status')) {
      e.preventDefault();
      const id = btn.dataset.id;
      const current = btn.dataset.status === 'active' ? 'active' : 'inactive';
      const next = current === 'active' ? 'inactive' : 'active';
      try {
        const form = new URLSearchParams();
        form.append('action', 'toggle_status');
        form.append('id', id);
        form.append('status', next);
        const res = await fetch('AJAX/get_toppings.php', { method: 'POST', body: form });
        const json = await res.json();
        if (json.success) {
          await loadToppings();
        } else {
          alert(json.message || 'Failed to update status');
        }
      } catch (err) {
        alert('Request failed');
      }
      return;
    }

    // Delete
    if (btn.matches('.topping-delete')) {
      e.preventDefault();
      const id = btn.dataset.id;
      if (!confirm('Delete this topping?')) return;
      const body = new URLSearchParams();
      body.append('action','delete');
      body.append('id', id);
      try {
        const res = await fetch(API, { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
          loadToppings();
        } else {
          if (res.status === 409 && data.message && /referenc/i.test(data.message)) {
            if (confirm(data.message + "\n\nMark it INACTIVE instead?")) {
              const body2 = new URLSearchParams();
              body2.append('action','toggle_status');
              body2.append('id', id);
              body2.append('status','inactive');
              const r2 = await fetch(API, { method: 'POST', body: body2 });
              const d2 = await r2.json();
              if (d2.success) loadToppings();
              else alert('Failed to set inactive: ' + (d2.message || 'unknown'));
            }
          } else {
            alert('Delete failed: ' + (data.message || 'unknown'));
          }
        }
      } catch (err) {
        console.error('delete topping error', err);
        alert('Delete request failed');
      }
      return;
    }
  });

  // Form submit handler
  const toppingForm = document.getElementById('toppingForm');
  if (toppingForm && !toppingForm._bound_by_main_js) {
    toppingForm._bound_by_main_js = true;
    toppingForm.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      const id = document.getElementById('toppingId').value || '';
      const name = document.getElementById('toppingName').value.trim();
      const price = document.getElementById('toppingPrice').value || '0';
      if (!name) {
        document.getElementById('toppingFormResult').textContent = 'Name required';
        return;
      }
      try {
        const form = new URLSearchParams();
        form.append('name', name);
        form.append('price', price);
        form.append('action', id ? 'update' : 'add');
        if (id) form.append('id', id);
        const res = await fetch('AJAX/get_toppings.php', { method: 'POST', body: form });
        const json = await res.json();
        if (json.success) {
          document.getElementById('addToppingModal').style.display = 'none';
          toppingForm.reset();
          await loadToppings(); // ✅ refresh table without reload
        } else {
          document.getElementById('toppingFormResult').textContent = json.message || 'Failed';
        }
      } catch (err) {
        document.getElementById('toppingFormResult').textContent = 'Request failed';
      }
    });
  }

  // initial load
  document.addEventListener('DOMContentLoaded', function () {
    loadToppings();
  });

})();

console.info('[admin] main.js loaded');



(function enforceToppingDeleteVisibility(){
  const tbody = document.querySelector('#toppingsTable tbody');
  // Use server flag if available; default to false (hide) for safety
  const isSuper = !!window.IS_SUPER_ADMIN;

  if (!tbody) return;

  function clean() {
    if (isSuper) return; // allow deletes for super admins
    // remove known-class buttons
    // fallback: remove plain "Delete" buttons in action column only
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

  function fetchOrders(status = '') {
    // Use the same container your initial render uses (do not change wrapper)
    const listContainer = document.querySelector('.live-orders-grid'); // or '#live-orders-list' if that is your inner list
    if (!listContainer) {
      console.warn('[admin] live-orders container not found');
      return;
    }
    fetch('AJAX/fetch_live_orders.php?status=' + encodeURIComponent(status), { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
      .then(res => res.text())
      .then(html => {
        // Replace only the inner content so layout styles remain
        listContainer.innerHTML = html;
        // Delegated click handler covers new buttons
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
  }  function attachOrderActionHandlers() {
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

(function pickedUpOrdersModule(){
  const tbody = document.getElementById('pickedup-orders-tbody');
  const pager = document.getElementById('pickedup-pagination');
  if (!tbody || !pager) return;

  let page = 1;
  const pageSize = 10;
  let totalPages = 1;
  let initialized = false;

  function fmtMoney(v){ return Number(v||0).toFixed(2); }

  function renderRows(list){
    tbody.innerHTML = '';
    if (!list.length){
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:12px;">No picked up orders.</td></tr>';
      return;
    }
    list.forEach(o=>{
      const items = (o.items||[]).map(i=>`${i.quantity}x ${i.name}${i.size?` (${i.size})`:''}`).join(', ');
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="padding:6px;">${o.reference_number}</td>
        <td style="padding:6px;">${o.customer_name}</td>
        <td style="padding:6px;">${items || '-'}</td>
        <td style="padding:6px;">${fmtMoney(o.total_amount)}</td>
        <td style="padding:6px;text-transform:capitalize;">${o.status}</td>
        <td style="padding:6px;">${new Date(o.created_at).toLocaleString()}</td>`;
      tbody.appendChild(tr);
    });
  }

  function btn(p,label,disabled=false,active=false){
    const b=document.createElement('button');
    b.type='button';
    b.className='pager-btn'+(active?' active':'');
    b.textContent=label;
    b.disabled=disabled;
    if(!disabled && !active){
      b.addEventListener('click',()=>load(p));
    }
    return b;
  }

  function renderPager(){
    pager.innerHTML='';
    if (totalPages <= 1){
      pager.style.display='none';
      return;
    }
    pager.style.display='flex';

    pager.appendChild(btn(page-1,'«',page===1));

    const windowSize = 5;
    let start = Math.max(1, page - Math.floor(windowSize/2));
    let end = start + windowSize - 1;
    if (end > totalPages) {
      end = totalPages;
      start = Math.max(1, end - windowSize + 1);
    }

    if (start > 1){
      pager.appendChild(btn(1,'1',false,page===1));
      if (start > 2){
        pager.appendChild(dotSpan());
      }
    }

    for (let p=start;p<=end;p++){
      pager.appendChild(btn(p,String(p),false,p===page));
    }

    if (end < totalPages){
      if (end < totalPages -1) pager.appendChild(dotSpan());
      pager.appendChild(btn(totalPages,String(totalPages),false,page===totalPages));
    }

    pager.appendChild(btn(page+1,'»',page===totalPages));
  }

  function dotSpan(){
    const s=document.createElement('span');
    s.className='pager-ellipsis';
    s.textContent='...';
    return s;
  }

  function load(next=1){
    page = next;
    console.debug('[pickedup] load page', page);
    tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:12px;">Loading…</td></tr>';
    fetch(`AJAX/fetch_pickedup_orders_page.php?page=${page}&pageSize=${pageSize}`,{cache:'no-store'})
      .then(r=>{
        console.debug('[pickedup] HTTP status', r.status);
        return r.json();
      })
      .then(d=>{
        console.debug('[pickedup] payload', d);
        if(!d.success) throw 0;
        totalPages = d.totalPages || 1;
        renderRows(d.orders||[]);
        renderPager();
        initialized = true;
      })
      .catch(err=>{
        console.error('[pickedup] load error', err);
        tbody.innerHTML='<tr><td colspan="6" style="text-align:center;color:#b91c1c;padding:12px;">Failed to load.</td></tr>';
        pager.innerHTML='';
      });
  }

  // Expose global API
  window.PickedUpOrders = {
    ensureLoaded(){ if(!initialized) load(1); },
    refresh(){ if(initialized) load(page); },
    goFirst(){ if(page!==1) load(1); },
    goLast(){ if(page!==totalPages) load(totalPages); }
  };

  // Lazy load when section becomes visible
  const observer = new MutationObserver(()=>{
    const sec = document.getElementById('order-history-section');
    if (sec && sec.classList.contains('active')) {
      window.PickedUpOrders.ensureLoaded();
    }
  });
  observer.observe(document.body,{subtree:true,attributes:true,attributeFilter:['class']});

  // If already active
  if (document.getElementById('order-history-section')?.classList.contains('active')){
    window.PickedUpOrders.ensureLoaded();
  }

  if (document.readyState !== 'loading') {
     // ensure initial attempt even if observer misses
     setTimeout(()=>window.PickedUpOrders?.ensureLoaded(), 250);
  } else {
     document.addEventListener('DOMContentLoaded', ()=>setTimeout(()=>window.PickedUpOrders?.ensureLoaded(), 250));
  }
})();