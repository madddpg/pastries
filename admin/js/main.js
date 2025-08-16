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
