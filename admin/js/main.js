document.addEventListener("DOMContentLoaded", () => {
  // Section switching logic
  function showSection(sectionName) {
    document.querySelectorAll(".content-section").forEach((section) => {
      section.classList.remove("active")
    })
    const targetSection = document.getElementById(`${sectionName}-section`)
    if (targetSection) {
      targetSection.classList.add("active")
    }
    document.querySelectorAll(".nav-item").forEach((item) => {
      item.classList.remove("active")
      if (item.getAttribute("data-section") === sectionName) {
        item.classList.add("active")
      }
    })
  }

  // Sidebar Navigation
  document.querySelectorAll(".nav-item").forEach((item) => {
    item.addEventListener("click", function (e) {
      e.preventDefault()
      const sectionName = this.getAttribute("data-section")
      showSection(sectionName)
    })
  })

  // Detect ?status=... and show Live Orders section
  function getUrlParameter(name) {
    name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]")
    const regex = new RegExp("[\\?&]" + name + "=([^&#]*)")
    const results = regex.exec(location.search)
    return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "))
  }

  if (getUrlParameter("status") !== "" || window.location.search.indexOf("status=") !== -1) {
    showSection("live-orders")
    // Set active tab
    const status = getUrlParameter("status")
    document.querySelectorAll("#live-orders-tabs .tab").forEach((tab) => {
      if ((tab.dataset.status || "") === status) {
        tab.classList.add("active")
      } else {
        tab.classList.remove("active")
      }
    })
    // Set sidebar active
    document.querySelectorAll(".nav-item").forEach((item) => {
      if (item.getAttribute("data-section") === "live-orders") {
        item.classList.add("active")
      } else {
        item.classList.remove("active")
      }
    })
  } else {
    showSection("order-history")
  }

  // Tabs (for tab navigation, not sidebar)
  document.querySelectorAll(".tabs .tab").forEach((tab) => {
    tab.addEventListener("click", function (e) {
      // Only handle non-live-orders tabs here
      if (this.closest("#live-orders-tabs")) return
      e.preventDefault()
      const tabGroup = this.closest(".tabs")
      if (tabGroup) {
        tabGroup.querySelectorAll(".tab").forEach((t) => t.classList.remove("active"))
      }
      this.classList.add("active")
    })
  })

  // Live Orders Tabs: reload with ?status=...
  const liveOrdersTabs = document.querySelectorAll('#live-orders-tabs .tab')
  liveOrdersTabs.forEach(tab => {
    tab.addEventListener('click', function(e) {
      e.preventDefault()
      // Remove active from all tabs, add to clicked
      liveOrdersTabs.forEach(t => t.classList.remove('active'))
      this.classList.add('active')
      // Get status from data-status
      const status = this.dataset.status
      window.location.search = status ? ('?status=' + encodeURIComponent(status)) : ''
    })
  })

  // Action Buttons (dropdown menu)
  document.querySelectorAll(".action-btn").forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.stopPropagation()
      document.querySelectorAll(".action-menu .dropdown-menu").forEach((menu) => {
        menu.style.display = "none"
      })
      const menu = this.closest(".action-menu")?.querySelector(".dropdown-menu")
      if (menu) {
        menu.style.display = menu.style.display === "block" ? "none" : "block"
      }
    })
  })

  // Hide dropdowns on document click
  document.addEventListener('click', function() {
    document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
      menu.style.display = "none";
    });
  });

  // Busy Mode Toggle (optional, only if .toggle input exists)
  const busyModeToggle = document.querySelector(".toggle input")
  if (busyModeToggle) {
    busyModeToggle.addEventListener("change", function () {
      const status = this.checked ? "enabled" : "disabled"
      showNotification(`Busy mode ${status}`)

      // Update shop status based on busy mode
      const shopStatus = document.querySelector(".shop-status")
      if (this.checked) {
        shopStatus.classList.remove("open")
        shopStatus.classList.add("closed")
        shopStatus.querySelector("span:first-child").textContent = "Closed For Order"
      } else {
        shopStatus.classList.remove("closed")
        shopStatus.classList.add("open")
        shopStatus.querySelector("span:first-child").textContent = "Open For Order"
      }
    })
  }

  // Make table rows clickable for order details
  const tableRows = document.querySelectorAll(".orders-table tbody tr, .products-table tbody tr, .stock-table tbody tr")
  tableRows.forEach((row) => {
    row.addEventListener("click", function (e) {
      // Don't trigger if clicking on action button or menu
      if (e.target.closest(".action-btn") || e.target.closest(".menu-item")) {
        return
      }

      const orderId = this.querySelector("td:first-child").textContent

      // Only allow modal for live orders, not order history
      if (currentSection === "products") {
        const productName = this.querySelector(".product-cell h4").textContent
        showNotification(`Viewing details for ${productName}`)
      } else if (currentSection === "stock") {
        const itemName = this.querySelector(".item-cell h4").textContent
        showNotification(`Viewing details for ${itemName}`)
      }
      // Do NOT call showOrderDetails for order-history
    })

    // Add hover class for better UX, but only for non-order-history tables
    if (!row.closest("#order-history-section")) {
      row.classList.add("clickable-row")
    }
  })

  // Live Order Action Buttons
  const orderActionBtns = document.querySelectorAll(
    ".btn-accept, .btn-reject, .btn-ready, .btn-cancel, .btn-complete, .btn-notify",
  )
  orderActionBtns.forEach((btn) => {
    btn.addEventListener("click", function () {
      showNotification(`Order ${this.textContent.trim()} action performed`)
    })
  })

  // Offer Action Buttons
  const offerActionBtns = document.querySelectorAll(".btn-edit, .btn-pause, .btn-delete, .btn-activate")
  offerActionBtns.forEach((btn) => {
    btn.addEventListener("click", function () {
      showNotification(`${this.textContent.trim()} action performed`)
    })
  })

  // Settings Navigation
  const settingsNavItems = document.querySelectorAll(".settings-nav-item")
  settingsNavItems.forEach((item) => {
    item.addEventListener("click", function (e) {
      e.preventDefault()

      // Remove active class from all settings nav items
      document.querySelectorAll(".settings-nav-item").forEach((nav) => nav.classList.remove("active"))

      // Add active class to clicked item
      this.classList.add("active")

      showNotification(`Switched to ${this.textContent} settings`)
    })
  })

  // Message Input
  const messageInput = document.querySelector(".message-input input")
  const sendBtn = document.querySelector(".btn-send")

  if (messageInput && sendBtn) {
    sendBtn.addEventListener("click", () => {
      if (messageInput.value.trim()) {
        // Add the message to the chat
        const messageHistory = document.querySelector(".message-history")
        const newMessage = document.createElement("div")
        newMessage.className = "message admin"
        newMessage.innerHTML = `
          <div class="message-bubble">
            <p>${messageInput.value}</p>
            <span class="message-time">${new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</span>
          </div>
        `
        messageHistory.appendChild(newMessage)
        messageHistory.scrollTop = messageHistory.scrollHeight

        // Clear the input
        messageInput.value = ""

        showNotification("Message sent")
      }
    })

    messageInput.addEventListener("keypress", function (e) {
      if (e.key === "Enter" && this.value.trim()) {
        // Trigger the send button click
        sendBtn.click()
      }
    })
  }

  // Conversation Items
  const conversationItems = document.querySelectorAll(".conversation-item")
  conversationItems.forEach((item) => {
    item.addEventListener("click", function () {
      // Remove active class from all conversation items
      document.querySelectorAll(".conversation-item").forEach((conv) => conv.classList.remove("active"))

      // Add active class to clicked item
      this.classList.add("active")

      // Remove unread badge if present
      const unreadBadge = this.querySelector(".unread-badge")
      if (unreadBadge) {
        unreadBadge.remove()
      }

      const customerName = this.querySelector("h4").textContent
      showNotification(`Opened conversation with ${customerName}`)
    })
  })

  // Primary and Secondary Buttons
  const actionButtons = document.querySelectorAll(".btn-primary, .btn-secondary")
  actionButtons.forEach((btn) => {
    btn.addEventListener("click", function () {
      showNotification(`${this.textContent.trim()} clicked`)
    })
  })

  // Settings Inputs
  const settingInputs = document.querySelectorAll(".setting-input, .setting-select")
  settingInputs.forEach((input) => {
    input.addEventListener("change", () => {
      showNotification("Setting updated")
    })
  })

  // User Profile Dropdown
  const userProfile = document.querySelector(".user-profile");
  if (userProfile) {
    userProfile.addEventListener("click", () => {
      showNotification("User profile menu opened");
    });
  }

  // Notification Icon
  const notificationIcon = document.querySelector(".notification-icon");
  if (notificationIcon) {
    notificationIcon.addEventListener("click", () => {
      showNotification("Notifications panel opened");
    });
  }

  // Helper function to show notification
  function showNotification(message) {
    const notification = document.createElement("div")
    notification.className = "notification"
    notification.textContent = message

    document.body.appendChild(notification)

    // Animate in
    setTimeout(() => {
      notification.classList.add("show")
    }, 10)

    // Remove after 3 seconds
    setTimeout(() => {
      notification.classList.remove("show")
      setTimeout(() => {
        notification.remove()
      }, 300)
    }, 3000)
  }

  // Helper function to show order details
  function showOrderDetails(orderId) {
    // Fetch order details via AJAX
    fetch(`order_detail.php?id=${encodeURIComponent(orderId)}`)
      .then(res => res.text())
      .then(html => {
        // Create modal for order details
        const modal = document.createElement("div")
        modal.className = "modal"
        modal.innerHTML = html

        // Close modal when clicking outside
        modal.addEventListener("click", (e) => {
          if (e.target === modal) {
            modal.remove()
          }
        })

        // If the loaded HTML contains a close button, wire it up
        setTimeout(() => {
          const closeBtn = modal.querySelector(".close-btn, .btn-close, .modal-close, .order-details-close");
          if (closeBtn) {
            closeBtn.addEventListener("click", () => modal.remove());
          }
        }, 100);

        document.body.appendChild(modal)
      })
  }

  // --- Live Orders AJAX Logic ---
  function fetchOrders(status = '') {
    fetch('../api/get_orders.php?status=' + encodeURIComponent(status))
      .then(res => res.text())
      .then(html => {
        document.getElementById('live-orders-list').innerHTML = html;
        attachOrderActionHandlers();
      });
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
        fetchOrders(document.querySelector('#live-orders-tabs .tab.active').dataset.status);
      } else {
        alert('Failed to update order');
      }
    });
  }

  // Attach handlers on page load for initial live orders
  if (document.getElementById('live-orders-list')) {
    attachOrderActionHandlers();
  }

  liveOrdersTabs.forEach(tab => {
    tab.addEventListener('click', function(e) {
      e.preventDefault();
      // Remove active from all tabs, add to clicked
      liveOrdersTabs.forEach(t => t.classList.remove('active'));
      this.classList.add('active');
      // Get status from data-status
      const status = this.dataset.status;
      // Only reload if not already on the correct status
      const url = status ? ('?status=' + encodeURIComponent(status)) : window.location.pathname;
      if (window.location.search !== url.replace(window.location.pathname, "")) {
        window.location.search = status ? ('?status=' + encodeURIComponent(status)) : '';
      }
    });
  });

  // --- Dashboard Stats Live Update ---
  function updateDashboardStats() {
    fetch('AJAX/dashboard_stats.php')
      .then(res => res.json())
      .then(stats => {
        document.getElementById('stat-total-orders').textContent = stats.totalOrdersToday;
        document.getElementById('stat-pending-orders').textContent = stats.pendingOrders;
        document.getElementById('stat-preparing-orders').textContent = stats.preparingOrders;
        document.getElementById('stat-ready-orders').textContent = stats.readyOrders;
      });
  }
  updateDashboardStats();
  setInterval(updateDashboardStats, 15000); // update every 15s
});