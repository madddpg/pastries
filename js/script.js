window.toggleProfileDropdown = function(event) {
    event.stopPropagation();
    var menu = document.getElementById("profileDropdownMenu");
    if (menu) {
        menu.classList.toggle("show");
    }
};

let cart = []
let currentSection = "home"
let isLoggedIn = false
let currentUser = null
let deliveryMethod = "pickup"
let selectedSize = "Grande"
let currentProduct = null
let lastDrinkType = 'cold'; 

// Navigation
function showSection(sectionName) {
  document.querySelectorAll(".section-content").forEach((section) => {
    section.style.display = "none"
    section.classList.remove("active")
  })
  const targetSection = document.getElementById(sectionName)
  if (targetSection) {
    targetSection.style.display = "block"
    targetSection.classList.add("active")
    if (sectionName === "products") {
      filterDrinks(lastDrinkType);
    }
  }
  document.querySelectorAll(".nav-item").forEach((item) => {
    item.classList.remove("active")
  })
  const clickedNavItem = Array.from(document.querySelectorAll(".nav-item")).find((item) => {
    const itemText = item.textContent.toLowerCase().trim()
    const targetText = sectionName.toLowerCase().trim()
    if (itemText === "menu" && targetText === "about") return true
    if (itemText === "shop" && targetText === "products") return true
    return itemText === targetText
  })
  if (clickedNavItem) {
    clickedNavItem.classList.add("active")
  }
  currentSection = sectionName
  window.scrollTo(0, 0)
}

// Product Modal Functions
function openProductModal(id, name, price, description, image) {
  if (!isLoggedIn) {
    showLoginModal();
    return;
  }
  currentProduct = { id, name, price, description, image };
  document.getElementById("modalProductName").textContent = name;
  document.getElementById("modalProductPrice").textContent = `Php ${price}`;
  document.getElementById("modalProductDescription").textContent = description;
  document.getElementById("modalProductImage").src = image;
  document.getElementById("modalProductImage").alt = name;
  selectedSize = "Grande";
  document.querySelectorAll(".size-btn").forEach((btn) => btn.classList.remove("active"));
  document.querySelector(".size-btn").classList.add("active");
  const modal = document.getElementById("productModal");
  modal.classList.add("active");
  modal.style.display = "flex";
  modal.style.alignItems = "center";
  modal.style.justifyContent = "center";
  modal.style.position = "fixed";
  modal.style.top = "0";
  modal.style.left = "0";
  modal.style.width = "100vw";
  modal.style.height = "100vh";
  modal.style.background = "rgba(0,0,0,0.15)";
  modal.style.zIndex = "3000";
  document.body.style.overflow = "hidden";

  // Ensure the yellow close button closes the modal
  const yellowCloseBtn = modal.querySelector('.product-modal-close-yellow');
  if (yellowCloseBtn) {
    yellowCloseBtn.onclick = function (e) {
      e.stopPropagation();
      closeProductModal();
    };
  }
}

function closeProductModal() {
  const modal = document.getElementById("productModal");
  if (modal) {
    modal.classList.remove("active");
    modal.style.display = "none"; 
  }
  document.body.style.overflow = "auto";
  currentProduct = null;
}

function selectSize(size) {
  selectedSize = size;
  document.querySelectorAll(".size-btn").forEach((btn) => {
    btn.classList.remove("active");
    if (btn.textContent.trim() === size) btn.classList.add("active");
  });
}

function addProductToCart() {
  if (currentProduct) {
    const productName = `${currentProduct.name} (${selectedSize})`;
    addToCart(currentProduct.id, productName, currentProduct.price, selectedSize);
    closeProductModal();
    showNotification("Product added to cart!", "success");
  }
}

// Cart functionality
function addToCart(product_id, name, price, size) {
  const existingItem = cart.find((item) =>
    item.product_id === product_id && item.name === name && item.size === size
  );
  if (existingItem) {
    existingItem.quantity += 1;
  } else {
    cart.push({
      product_id: product_id, 
      name: name,
      price: price,
      quantity: 1,
      size: size
    });
  }
  updateCartCount();
  updateCartDisplay();
  if (!currentProduct) {
    const button = event.target;
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-check"></i> Added!';
    button.style.background = "linear-gradient(135deg, #10B981, #059669)";
    setTimeout(() => {
      button.innerHTML = originalText;
      button.style.background = "";
    }, 1500);
  }
}

function removeFromCart(product_id, size) {
  cart = cart.filter((item) => !(item.product_id === product_id && item.size === size));
  updateCartCount();
  updateCartDisplay();
}

function updateQuantity(product_id, change, size) {
  const item = cart.find((item) => item.product_id === product_id && item.size === size);
  if (item) {
    item.quantity += change;
    if (item.quantity <= 0) {
      removeFromCart(product_id, size);
    } else {
      updateCartCount();
      updateCartDisplay();
    }
  }
}

function getTotalItems() {
  return cart.reduce((sum, item) => sum + item.quantity, 0);
}

function getCartKey() {
  if (currentUser && currentUser.id) {
    return `cart_user_${currentUser.id}`;
  }
  return "cart_guest";
}

function loadCart() {
  try {
    const key = getCartKey();
    const savedCart = localStorage.getItem(key);
    if (savedCart) {
      cart = JSON.parse(savedCart);
    } else {
      cart = [];
    }
  } catch {
    cart = [];
  }
}

function sendOTP(){
  const email = document.getElementById("registerEmail");
  const otp = document.getElementById("otp");


}
function saveCart() {
  const key = getCartKey();
  localStorage.setItem(key, JSON.stringify(cart));
}

function updateCartCount() {
  const totalItems = getTotalItems();
  document.getElementById("cartCount").textContent = totalItems;
  const modalCartCount = document.getElementById("cartCountModal");
  if (modalCartCount) {
    modalCartCount.textContent = totalItems;
  }
  saveCart();
}

function updateCartDisplay() {
  const cartItemsContainer = document.getElementById("cartItems");
  const cartTotalContainer = document.getElementById("cartTotal");
  if (cart.length === 0) {
    cartItemsContainer.innerHTML = `
            <div class="empty-cart">
                <i class="fas fa-shopping-cart"></i>
                <h4>Your cart is empty</h4>
                <p>Add some delicious coffee to get started!</p>
            </div>
        `;
    cartTotalContainer.innerHTML = "";
    document.getElementById("deliveryOptions").style.display = "none";
    return;
  }
  cartItemsContainer.innerHTML = cart
    .map(
      (item) => `
        <div class="cart-item">
            <div class="cart-item-info">
                <div class="cart-item-name">${item.name}</div>
                <div class="cart-item-price">₱${item.price.toFixed(2)} each</div>
            </div>
            <div class="quantity-controls">
                <button class="quantity-btn" onclick="updateQuantity('${item.product_id}', -1, '${item.size}')">-</button>
                <span class="quantity">${item.quantity}</span>
                <button class="quantity-btn" onclick="updateQuantity('${item.product_id}', 1, '${item.size}')">+</button>
            </div>
            <button class="remove-item" onclick="removeFromCart('${item.product_id}', '${item.size}')">Remove</button>
        </div>
    `
    )
    .join("");
  const total = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  cartTotalContainer.innerHTML = `
        <div class="total-amount">Total: ₱${total.toFixed(2)}</div>
        <button class="checkout-btn" onclick="handleCheckout()">
            <i class="fas fa-credit-card"></i> Checkout
        </button>
    `;
}

function openCart() {
  updateCartDisplay();
  document.getElementById("cartModal").classList.add("active");
  document.body.style.overflow = "hidden";
}

function closeCart() {
  document.getElementById("cartModal").classList.remove("active");
  document.body.style.overflow = "auto";
  document.getElementById("deliveryOptions").style.display = "none";
  deliveryMethod = "pickup";
}


function startCheckout() {
  if (cart.length === 0) return;
  let deliveryOptions = document.getElementById("deliveryOptions");
  if (deliveryOptions) {
    deliveryOptions.style.display = "block";
    return;
  }
  deliveryOptions = document.createElement("div");
  deliveryOptions.id = "deliveryOptions";
  deliveryOptions.className = "delivery-options";
  deliveryOptions.style.marginTop = "20px";
  deliveryOptions.innerHTML = `
    <h4 style="font-size:1.35rem;font-weight:800;color:#40534b;margin-bottom:18px;">
      Pickup Details
    </h4>
    <form id="pickupForm" style="margin-bottom:0;">
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-weight:600;">Pickup Name</label>
        <input type="text" id="pickupName" placeholder="Enter your name" required style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #e5e7eb;">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-weight:600;">Pickup Location</label>
        <input type="text" id="pickupLocation" value="123 Coffee Street, Downtown District, City, State 12345" required style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #e5e7eb;">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-weight:600;">Pickup Time</label>
        <input type="time" id="pickupTime" required style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #e5e7eb;">
        <p id="pickupTimeNote" style="margin-top:6px;font-size:0.95em;color:#b45309;">Note: We are open only 3:00 p.m to 9:00 p.m. Thank you!</p>
      </div>
      <div class="form-group" style="margin-bottom:18px;">
        <label style="font-weight:600;">Special Instructions (Optional)</label>
        <input type="text" id="specialInstructions" placeholder="e.g. Please call when outside" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #e5e7eb;">
      </div>
    </form>
  `;
  // Insert after cartItems, before cartTotal
  const cartContent = document.querySelector(".cart-content");
  const cartTotal = document.getElementById("cartTotal");
  if (cartContent && cartTotal) {
    cartContent.insertBefore(deliveryOptions, cartTotal);
  } else if (cartContent) {
    cartContent.appendChild(deliveryOptions);
  }

setTimeout(() => {
  const pickupTimeInput = document.getElementById("pickupTime");
  const note = document.getElementById("pickupTimeNote");

  if (pickupTimeInput && note) {
    pickupTimeInput.min = "15:00";
    pickupTimeInput.max = "20:30";

    pickupTimeInput.addEventListener("input", function () {
      const val = this.value;
      if (!val) {
        note.textContent = "Note: We are open only 3:00 p.m to 8:30 p.m. Thank you!";
        note.style.color = "#b45309";
        this.setCustomValidity("");
        return;
      }

      const [h, m] = val.split(":").map(Number);
      const mins = h * 60 + m;

      if (mins < 900 || mins > 1230) {
        note.textContent = "Please select a time between 3:00 p.m and 8:30 p.m.";
        note.style.color = "#dc2626";
        this.setCustomValidity("Pickup time must be between 3:00 p.m and 8:30 p.m.");
      } else {
        note.textContent = "Note: We are open only 3:00 p.m to 8:30 p.m. Thank you!";
        note.style.color = "#b45309";
        this.setCustomValidity("");
      }
    });
  }
}, 0);
}


function completePickupCheckout() {
  const pickup_name = document.getElementById("pickupName").value;
  const pickup_location = document.getElementById("pickupLocation").value;
  const pickup_time = document.getElementById("pickupTime").value;
  const special_instructions = document.getElementById("specialInstructions").value;

  fetch('pickup_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `pickup_name=${encodeURIComponent(pickup_name)}&pickup_location=${encodeURIComponent(pickup_location)}&pickup_time=${encodeURIComponent(pickup_time)}&special_instructions=${encodeURIComponent(special_instructions)}`
  })
    .then(res => {
      console.log('Pickup checkout response status:', res.status);
      return res.text().then(text => {
        console.log('Pickup checkout raw response:', text);
        try {
          return JSON.parse(text);
        } catch (e) {
          showNotification("Invalid server response.", "error");
          throw e;
        }
      });
    })
    .then(data => {
      if (data.success) {
        showNotification("Pickup order placed successfully!", "success");
        closeCart();
        cart = [];
        updateCartCount();
        updateCartDisplay();
      } else {
        showNotification(data.message || "Pickup order failed.", "error");
      }
    })
    .catch((err) => {
      console.error('Pickup checkout error:', err);
      showNotification("Network error. Please try again.", "error");
    });
}

// Auth functions
function showAuthModal() {
  if (isLoggedIn) {
    if (currentUser && currentUser.id) {
      localStorage.removeItem(getCartKey());
    }
    currentUser = null;
    cart = [];
    updateCartCount();
    updateCartDisplay();
    logout();
  } else {
    showLoginModal();
  }
}

function showLoginModal() {
  document.getElementById("loginModal").classList.add("active");
  document.body.style.overflow = "hidden";
}

function showRegisterModal() {
  document.getElementById("registerModal").classList.add("active");
  document.body.style.overflow = "hidden";
}

function closeAuthModal() {
  document.getElementById("loginModal").classList.remove("active");
  document.getElementById("registerModal").classList.remove("active");
  document.body.style.overflow = "auto";
  document.querySelectorAll(".auth-form").forEach((form) => form.reset());
  document.querySelectorAll(".success-message").forEach((msg) => msg.classList.remove("show"));
  document.querySelectorAll(".auth-btn").forEach((btn) => {
    btn.classList.remove("loading");
    btn.disabled = false;
  });
}

function switchToRegister() {
  document.getElementById("loginModal").classList.remove("active");
  document.getElementById("registerModal").classList.add("active");
}

function switchToLogin() {
  document.getElementById("registerModal").classList.remove("active");
  document.getElementById("loginModal").classList.add("active");
}

function handleLogin(event) {
    event.preventDefault();
    var user_email = document.getElementById('loginEmail').value;
    var password = document.getElementById('loginPassword').value;
    var loginBtn = document.getElementById('loginBtn');
    loginBtn.disabled = true;
    loginBtn.classList.add("loading");

    fetch('login.php', {
        method: 'POST',
        body: new URLSearchParams({
            user_email: user_email,
            password: password
        }),
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        credentials: 'same-origin' 
    })
    .then(res => res.json())
    .then(data => {
        loginBtn.disabled = false;
        loginBtn.classList.remove("loading");
        if (data.success) {
            isLoggedIn = true;
            window.PHP_IS_LOGGED_IN = true;
            currentUser = {
                id: data.user_id,
                fullname: data.fullname,
                firstName: data.firstName,
                initials: data.initials,
                is_admin: data.is_admin
            };
            if (document.getElementById("profileText"))
                document.getElementById("profileText").textContent = data.firstName;
            if (document.getElementById("profileAvatar"))
                document.getElementById("profileAvatar").innerHTML = data.initials;
            let navbarUser = document.querySelector(".navbar-username");
            if (navbarUser) navbarUser.textContent = data.fullname;
            document.getElementById("loginSuccess").classList.add("show");
            if (window.updateProfileDropdownMenu) window.updateProfileDropdownMenu(true);
            loadCart();
            updateCartCount();
            updateCartDisplay();
            setTimeout(() => {
                closeAuthModal();
                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            }, 1000);
        } else {
            document.getElementById('loginEmailError').textContent = data.message || 'Login failed.';
        }
    })
    .catch(() => {
        loginBtn.disabled = false;
        loginBtn.classList.remove("loading");
        document.getElementById('loginEmailError').textContent = 'Login failed. Please try again.';
    });
}



// === Testimonial Image Modal Logic ===
window.openTestimonialModal = function(imgElem) {
  var modal = document.getElementById('testimonialImageModal');
  var modalImg = document.getElementById('testimonialModalImg');
  if (modal && modalImg && imgElem) {
    modalImg.src = imgElem.src;
    modalImg.alt = imgElem.alt || 'Testimonial';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

window.closeTestimonialModal = function() {
  var modal = document.getElementById('testimonialImageModal');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
  }
}
function logout(event) {
  if (event) event.stopPropagation();
  fetch("logout.php", { method: "POST" })
    .then(() => {
      window.location.reload();
    });
}


// Modify the handleRegister function (around line 340-375)

function handleRegister(event) {
  event.preventDefault();
  const name = document.getElementById("registerName").value;
  const lastName = document.getElementById("registerLastName").value;
  const email = document.getElementById("registerEmail").value;
  const password = document.getElementById("registerPassword").value;
  const confirmPassword = document.getElementById("confirmPassword").value;
  const registerBtn = document.getElementById("registerBtn");
  
  if (password !== confirmPassword) {
    alert("Passwords do not match!");
    return;
  }
  
  registerBtn.classList.add("loading");
  registerBtn.disabled = true;
  
  const formData = new FormData();
  formData.append('registerName', name);
  formData.append('registerLastName', lastName);
  formData.append('registerEmail', email);
  formData.append('registerPassword', password);
  formData.append('confirmPassword', confirmPassword);
  
  fetch('register.php', {
    method: 'POST',
    body: formData
  })
    .then(response => response.json())
    .then(data => {
      // If backend signals that verification is required, start OTP flow
      if (data.success && (data.requires_verification || data.pending_verification)) {
        showNotification("Verification sent! Please check your email.", "success");
        
        // Show OTP modal WITHOUT sending another email
        showOtpModal(data.email || email);
        
        // REMOVE THIS LINE - it's causing the second email:
        // setTimeout(() => sendOTP(data.email || email), 150);
        
        // Instead, just set up the OTP state with the data from the server
        otpState.email = data.email || email;
        otpState.expiresAt = data.expires_at || 0;
        startOtpTimers();
        
        return; // do not reload yet
      }

      if (data.success) {
        showNotification("Registration successful! You can now log in.", "success");
        setTimeout(() => window.location.reload(), 1500);
      } else {
        showNotification(data.message || "Registration failed. Please try again.", "error");
      }
    })
    .catch(() => {
      showNotification("Registration failed. Please try again.", "error");
    })
    .finally(() => {
      registerBtn.classList.remove("loading");
      registerBtn.disabled = false;
    });
}


function showNotification(message, type = "success") {
  const notification = document.createElement("div");
  notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === "success" ? "linear-gradient(135deg, #10B981, #059669)" : "linear-gradient(135deg, #EF4444, #DC2626)"};
        color: white;
        padding: 16px 24px;
        border-radius: 12px;
        font-weight: 600;
        z-index: 9999;
        box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        animation: slideIn 0.3s ease;
    `;
  notification.innerHTML = `<i class="fas fa-check-circle" style="margin-right: 8px;"></i>${message}`;
  document.body.appendChild(notification);
  setTimeout(() => {
    notification.remove();
  }, 3000);
}

function filterDrinks(type) {
  lastDrinkType = type;
  document.getElementById("hotDrinksBtn").classList.remove("active");
  document.getElementById("coldDrinksBtn").classList.remove("active");
   document.getElementById("pastriesBtn").classList.remove("active");
  if (type === "hot") {
    document.getElementById("hotDrinksBtn").classList.add("active");
  } else if (type === "cold") {
    document.getElementById("coldDrinksBtn").classList.add("active");
  } else {
    document.getElementById("pastriesBtn").classList.add("active");
  }

  document.querySelectorAll('.product-item').forEach(item => {
    item.style.opacity = '0';
  });

  setTimeout(() => {
    document.querySelectorAll('.product-item').forEach(item => {
      if (item.getAttribute('data-type') === type) {
        item.style.display = '';
        setTimeout(() => { item.style.opacity = '1'; }, 10);
      } else {
        item.style.display = 'none';
      }
    });
  }, 200);
}


window.filterDrinks = function(type) {
    loadTopProducts(type); 
    document.querySelectorAll('.product-item').forEach(function(item) {
        if (item.getAttribute('data-type') === type) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
    document.getElementById('hotDrinksBtn').classList.toggle('active', type === 'hot');
    document.getElementById('coldDrinksBtn').classList.toggle('active', type === 'cold');
    document.getElementById('pastriesBtn').classList.toggle('active', type === 'pastries');
}
let otpState = {
  email: null,
  expiresAt: 0,
  cooldownUntil: 0,
  countdownTimer: null,
  cooldownTimer: null
};

function getCsrfToken() {
  // Prefer meta tag if present, else a global set by PHP
  const meta = document.querySelector('meta[name="csrf_token"]');
  if (meta && meta.content) return meta.content;
  if (typeof window.PHP_CSRF_TOKEN !== 'undefined') return window.PHP_CSRF_TOKEN;
  return ''; // backend should ignore if not required
}

function showOtpModal(email) {
  otpState.email = email || (document.getElementById("registerEmail")?.value?.trim() || null);

  let modal = document.getElementById("otpModal");
  if (!modal) {
    modal = document.createElement("div");
    modal.id = "otpModal";
    modal.style.cssText = `
      position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
      background: rgba(0,0,0,0.2); z-index: 4000;`;
    modal.innerHTML = `
      <div style="background:#fffbe9; border-radius:16px; padding:24px; width: 360px; box-shadow:0 10px 30px rgba(0,0,0,.12);">
        <h3 style="margin:0 0 6px; color:#2d4a3a;">Verify your email</h3>
        <div id="otpModalMsg" class="muted" style="margin-bottom:12px;color:#374151;">
          We sent a 6-digit code to <b id="otpModalEmail"></b>.
        </div>
        <label style="font-weight:600;">Enter code</label>
        <input id="otpInput" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8"
               style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #e5e7eb;margin-top:6px;"
               placeholder="e.g. 123456" />
        <div id="otpError" style="color:#b00020;min-height:20px;margin-top:6px;"></div>
        <div class="row" style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
          <div class="muted" id="otpCountdown" style="color:#6b7280;font-size:0.95em;"></div>
          <button id="resendOtpBtn" class="btn btn-link" style="background:none;border:none;color:#b45309;cursor:pointer;">Resend</button>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
          <button id="closeOtpBtn" style="padding:10px 14px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;">Cancel</button>
          <button id="verifyOtpBtn" style="padding:10px 14px;border-radius:10px;border:none;background:#10B981;color:#fff;font-weight:700;">Verify</button>
        </div>
      </div>`;
    document.body.appendChild(modal);

    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeOtpModal();
    });
    modal.querySelector('#closeOtpBtn').addEventListener('click', closeOtpModal);
    modal.querySelector('#verifyOtpBtn').addEventListener('click', verifyOTP);
    modal.querySelector('#resendOtpBtn').addEventListener('click', resendOTP);
    modal.querySelector('#otpInput').addEventListener('keyup', (e) => {
      if (e.key === 'Enter') verifyOTP();
    });
  }

  modal.querySelector('#otpModalEmail').textContent = otpState.email || 'your email';
  modal.querySelector('#otpError').textContent = '';
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeOtpModal() {
  const modal = document.getElementById("otpModal");
  if (modal) modal.style.display = 'none';
  document.body.style.overflow = 'auto';
  if (otpState.countdownTimer) clearInterval(otpState.countdownTimer);
  if (otpState.cooldownTimer) clearInterval(otpState.cooldownTimer);
}

function updateOtpCountdown() {
  const el = document.getElementById('otpCountdown');
  if (!el) return;
  const now = Math.floor(Date.now() / 1000);

  // Expiry text
  if (otpState.expiresAt && now < otpState.expiresAt) {
    const remain = otpState.expiresAt - now;
    const m = Math.floor(remain / 60);
    const s = remain % 60;
    el.textContent = `Code expires in ${m}:${String(s).padStart(2, '0')}`;
  } else if (otpState.expiresAt) {
    el.textContent = 'Code expired. Please resend.';
  } else {
    el.textContent = '';
  }

  // Cooldown for resend button
  const resendBtn = document.getElementById('resendOtpBtn');
  if (resendBtn) {
    if (otpState.cooldownUntil && now < otpState.cooldownUntil) {
      const cr = otpState.cooldownUntil - now;
      resendBtn.disabled = true;
      resendBtn.textContent = `Resend (${cr}s)`;
    } else {
      resendBtn.disabled = false;
      resendBtn.textContent = 'Resend';
    }
  }
}

function startOtpTimers() {
  if (otpState.countdownTimer) clearInterval(otpState.countdownTimer);
  if (otpState.cooldownTimer) clearInterval(otpState.cooldownTimer);
  updateOtpCountdown();
  otpState.countdownTimer = setInterval(updateOtpCountdown, 1000);
  otpState.cooldownTimer = otpState.countdownTimer; // same cadence
}

async function sendOTP(emailParam) {
  const email = (emailParam || document.getElementById("registerEmail")?.value || '').trim();
  if (!email) {
    showNotification("Please enter your email first.", "error");
    return;
  }

  try {
    const res = await fetch('AJAX/send_otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
      credentials: 'same-origin',
      body: JSON.stringify({ email })
    });
    const data = await res.json().catch(() => ({}));
    if (data.success) {
      otpState.email = data.email || email;
      otpState.expiresAt = data.expires_at || 0;       // unix seconds
      otpState.cooldownUntil = data.cooldown ? Math.floor(Date.now()/1000) + Number(data.cooldown) : 0;
      showOtpModal(otpState.email);
      startOtpTimers();
    } else {
      showOtpModal(email);
      showNotification(data.message || "Failed to send code.", "error");
    }
  } catch {
    showOtpModal(email);
    showNotification("Network error while sending code.", "error");
  }
}

async function resendOTP(e) {
  if (e) e.preventDefault();
  const now = Math.floor(Date.now() / 1000);
  if (otpState.cooldownUntil && now < otpState.cooldownUntil) return;

  await sendOTP(otpState.email);
}

async function verifyOTP() {
  const input = document.getElementById('otpInput');
  const errorEl = document.getElementById('otpError');
  const code = (input?.value || '').replace(/\D+/g, '');
  if (!code || code.length < 4) {
    errorEl.textContent = "Enter a valid code.";
    return;
  }

  const btn = document.getElementById('verifyOtpBtn');
  btn.disabled = true;
  btn.textContent = 'Verifying...';

  try {
    const res = await fetch('AJAX/verify_otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
      credentials: 'same-origin',
      body: JSON.stringify({ otp: code })
    });
    const dataText = await res.text();
    let data;
    try { data = JSON.parse(dataText); } catch { data = {}; }

    if (data.success) {
      // Show a more prominent notification about successful verification
      showNotification("Verification successful! Your account has been created.", "success");
      
      // Display verification status in the modal before closing it
      const modalMsg = document.getElementById('otpModalMsg');
      if (modalMsg) {
        modalMsg.innerHTML = '<div style="color:#10B981;font-weight:bold;margin:10px 0;"><i class="fas fa-check-circle"></i> Verification successful!</div>';
      }
      
      // Set a short delay to allow the user to see the success message
      setTimeout(() => {
        closeOtpModal();
        // If backend returns redirect, follow it. Else just reload.
        if (data.redirect) {
          window.location.href = data.redirect;
        } else {
          window.location.reload();
        }
      }, 1500);
    } else {
      errorEl.textContent = data.message || "Incorrect code.";
      if (typeof data.locked_for === 'number' && data.locked_for > 0) {
        showNotification(`Too many attempts. Try again in ${Math.ceil(data.locked_for/60)} min.`, "error");
      }
      if (typeof data.expires_at === 'number') {
        otpState.expiresAt = data.expires_at;
      }
    }
  } catch {
    errorEl.textContent = "Network error. Please try again.";
  } finally {
    btn.disabled = false;
    btn.textContent = 'Verify';
  }
}
// Add this function to handle order status notifications

// Add these variables at the top of your file
let previousOrderStatuses = {};
let initialLoadComplete = false;

function checkOrderStatusUpdates() {
  if (!isLoggedIn) {
    console.log("Not logged in, skipping check");
    return;
  }
  
  // Add random parameter to prevent caching
  const timestamp = new Date().getTime();
  
  fetch(`AJAX/check_order_status.php?_=${timestamp}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Cache-Control': 'no-cache, no-store',
      'Pragma': 'no-cache'
    }
  })
  .then(response => response.text())
  .then(text => {
    try {
      return JSON.parse(text);
    } catch (e) {
      console.error("JSON parse error:", e);
      throw e;
    }
  })
  .then(data => {
    // Process notifications for unnotified updates
    if (data.status_updates && data.status_updates.length > 0) {
      console.log(`Found ${data.status_updates.length} status updates`);
      data.status_updates.forEach(update => {
        showOrderStatusNotification(update);
      });
    } 
    
    // Process all recent orders
    if (data.all_recent_orders && data.all_recent_orders.length > 0) {
      console.log(`Found ${data.all_recent_orders.length} recent orders`);
      
      // Compare current statuses with previous ones
      data.all_recent_orders.forEach(order => {
        const refNum = order.reference_number;
        
        // If this isn't the first load and status has changed
        if (initialLoadComplete && 
            previousOrderStatuses[refNum] && 
            previousOrderStatuses[refNum] !== order.status) {
          
          console.log(`Status changed for ${refNum}: ${previousOrderStatuses[refNum]} -> ${order.status}`);
          
          // Show notification for the changed status
          showOrderStatusNotification(order);
        }
        
        // Update stored status
        previousOrderStatuses[refNum] = order.status;
      });
      
      // Mark initial load as complete
      if (!initialLoadComplete) {
        initialLoadComplete = true;
      }
    }
  })
  .catch(error => {
    console.error("Error checking order status:", error);
  });
}

function showOrderStatusNotification(update) {
  // Create status message based on the update
  let icon = 'info-circle';
  let color = '#10B981';
  let message = '';
  
  switch(update.status.toLowerCase()) {
    case 'approved':
      icon = 'check-circle';
      message = `Order #${update.reference_number} has been approved!`;
      color = '#10B981'; // Green
      break;
    case 'ready':
      icon = 'mug-hot';
      message = `Order #${update.reference_number} is ready for pickup!`;
      color = '#b45309'; // Coffee brown
      break;
    case 'pending':
      icon = 'clock';
      message = `Order #${update.reference_number} is pending approval.`;
      color = '#3B82F6'; // Blue
      break;
    case 'declined':
      icon = 'times-circle';
      message = `Order #${update.reference_number} was declined.`;
      color = '#DC2626'; // Red
      break;
    case 'completed':
      icon = 'check-double';
      message = `Order #${update.reference_number} has been completed. Thank you!`;
      color = '#10B981'; // Green
      break;
    case 'picked up':
      icon = 'hand-holding';
      message = `Order #${update.reference_number} has been picked up. Thank you!`;
      color = '#10B981'; // Green
      break;
    default:
      message = `Order #${update.reference_number} status: ${update.status}`;
  }

  console.log("Showing notification:", message);

  // Create notification element
  const notification = document.createElement("div");
  notification.className = 'order-status-notification';
  notification.style.cssText = `
    position: fixed;
    top: 90px;
    left: 50%;
    transform: translateX(-50%);
    background: ${color};
    color: white;
    padding: 18px 24px;
    border-radius: 12px;
    font-weight: 600;
    z-index: 9999;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    animation: slideDown 0.5s ease;
    display: flex;
    align-items: center;
    min-width: 300px;
    max-width: 90%;
    cursor: pointer;
  `;
  
  notification.innerHTML = `
    <i class="fas fa-${icon}" style="margin-right: 12px; font-size: 24px;"></i>
    <div>
      <div style="font-size: 16px;">${message}</div>
      <div style="font-size: 14px; opacity: 0.9; margin-top: 4px;">
        ${update.timestamp ? new Date(update.timestamp).toLocaleString() : ''}
      </div>
    </div>
  `;
  
  // Add click handler to dismiss
  notification.addEventListener('click', () => {
    notification.style.animation = 'fadeOut 0.5s ease';
    setTimeout(() => notification.remove(), 500);
  });
  
  document.body.appendChild(notification);

  // Remove after 10 seconds
  setTimeout(() => {
    if (document.body.contains(notification)) {
      notification.style.animation = 'fadeOut 0.5s ease';
      setTimeout(() => notification.remove(), 500);
    }
  }, 10000);
}

// Add animation styles on page load
document.addEventListener('DOMContentLoaded', function() {
  // Only add animation styles once
  if (!document.getElementById('notification-animations')) {
    const style = document.createElement('style');
    style.id = 'notification-animations';
    style.textContent = `
      @keyframes slideDown {
        from { transform: translate(-50%, -100px); opacity: 0; }
        to { transform: translate(-50%, 0); opacity: 1; }
      }
      @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
      }
    `;
    document.head.appendChild(style);
  }
  
  // Set up notification checking
  if (isLoggedIn) {
    console.log("Setting up order status notifications");
    
    // Clear any existing interval
    if (window.statusCheckInterval) {
      clearInterval(window.statusCheckInterval);
    }
    
    // Check right away and then periodically
    checkOrderStatusUpdates();
    window.statusCheckInterval = setInterval(checkOrderStatusUpdates, 5000);
  }
});
// Add animation styles to document
document.addEventListener('DOMContentLoaded', function() {
  const style = document.createElement('style');
  style.textContent = `
    @keyframes slideDown {
      from {
        transform: translate(-50%, -100px);
        opacity: 0;
      }
      to {
        transform: translate(-50%, 0);
        opacity: 1;
      }
    }
    
    @keyframes fadeOut {
      from {
        opacity: 1;
      }
      to {
        opacity: 0;
      }
    }
  `;
  document.head.appendChild(style);
  
  // Create directory for sound if needed
  // (you'll need to add a notification.mp3 file to your project)
  
  // Check for updates immediately and then frequently for real-time feel
  checkOrderStatusUpdates();
  setInterval(checkOrderStatusUpdates, 5000); // Check every 5 seconds for more real-time feel
});

// Add animation styles to document
document.addEventListener('DOMContentLoaded', function() {
  const style = document.createElement('style');
  style.textContent = `
    @keyframes slideDown {
      from {
        transform: translate(-50%, -100px);
        opacity: 0;
      }
      to {
        transform: translate(-50%, 0);
        opacity: 1;
      }
    }
    
    @keyframes fadeOut {
      from {
        opacity: 1;
      }
      to {
        opacity: 0;
      }
    }
  `;
  document.head.appendChild(style);
  
  // Check for updates immediately and then periodically
  checkOrderStatusUpdates();
  setInterval(checkOrderStatusUpdates, 30000); // Check every 30 seconds
});

function loadTopProducts(category) {
    fetch('AJAX/get_top_products.php?category=' + encodeURIComponent(category))
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('topProductsContainer');
            if (!container) return;
            let html = '';
            if (data.success && data.products.length > 0) {
                html += `<div class=\"products-header\" style=\"margin-bottom:1.5em;\">
                    <h3 style=\"font-size:2.2rem;font-weight:800;color:#b45309;margin-bottom:0.5em;\">Top Products (${category.charAt(0).toUpperCase() + category.slice(1)} Drinks)</h3>
                    <div class=\"top-products-list d-flex flex-wrap justify-content-center gap-4\">`;
                data.products.forEach((tp, idx) => {
                    let imgSrc = tp.image;
                    if (!imgSrc.startsWith('img/')) imgSrc = 'img/' + imgSrc.replace(/^\/+/, '');
                    html += `<div class=\"top-product-card shadow-sm rounded-4 p-3 text-center\" style=\"background:#fffbe9;min-width:220px;max-width:260px;\">
                        <div class=\"top-product-image mb-2\" style=\"height:120px;display:flex;align-items:center;justify-content:center;\">
                            <img src=\"${imgSrc}\" alt=\"${tp.name}\" style=\"max-height:100px;max-width:100%;border-radius:12px;object-fit:cover;\">
                        </div>
                        <h4 style=\"font-weight:700; color:#2d4a3a; margin-bottom:0.3em;\">${tp.name}</h4>
                        <div style=\"font-size:0.98em; color:#374151; min-height:48px; margin-bottom:0.5em;\">${tp.description}</div>
                        <div class=\"badge bg-warning text-dark mb-2\" style=\"font-size:0.95em;\">#${idx+1} Best Seller</div>
                        <div style=\"font-size:0.95em; color:#b45309; font-weight:600;\">Sold: ${tp.sales_count}</div>
                    </div>`;
                });
                html += '</div></div>';
            } else {
                html = `<div class=\"products-header\"><h3 style=\"font-size:2.2rem;font-weight:800;color:#b45309;margin-bottom:0.5em;\">Top Products (${category.charAt(0).toUpperCase() + category.slice(1)} Drinks)</h3><div class=\"text-muted\" style=\"font-size:1.1em;\">No products to show yet.</div></div>`;
            }
            container.innerHTML = html;
        });
}


function handleViewProduct(id, name, price, description, image) {
  openProductModal(id, name, price, description, image);
}


document.addEventListener("click", (event) => {
  const cartModal = document.getElementById("cartModal");
  const loginModal = document.getElementById("loginModal");
  const registerModal = document.getElementById("registerModal");
  const productModal = document.getElementById("productModal");
  if (event.target === cartModal) closeCart();
  if (event.target === loginModal || event.target === registerModal) closeAuthModal();
  if (event.target === productModal) closeProductModal();
});


document.addEventListener('click', function(event) {
    var menu = document.getElementById("profileDropdownMenu");
    var btn = document.getElementById("profileDropdownBtn");
    if (menu && menu.classList.contains("show")) {
        if (!menu.contains(event.target) && (!btn || !btn.contains(event.target))) {
            menu.classList.remove("show");
        }
    }
});




document.addEventListener("DOMContentLoaded", () => {
  console.log("[DEBUG] On load: window.PHP_IS_LOGGED_IN =", window.PHP_IS_LOGGED_IN, ", isLoggedIn =", isLoggedIn);
  if (typeof window.PHP_IS_LOGGED_IN !== "undefined" && window.PHP_IS_LOGGED_IN) {
    isLoggedIn = true;
    if (window.PHP_USER_FULLNAME) {
      currentUser = {
        name: window.PHP_USER_FULLNAME,
        initials: window.PHP_USER_FULLNAME.split(" ").map(n => n[0]).join("").toUpperCase().substring(0, 2)
      };
      document.getElementById("profileText").textContent = window.PHP_USER_FULLNAME.split(" ")[0];
      document.getElementById("profileAvatar").innerHTML = currentUser.initials;
    }
    if (window.PHP_USER_ID) {
      currentUser.id = window.PHP_USER_ID;
    }
  } else {
    console.error("PHP_IS_LOGGED_IN is false or undefined.");
  }
  loadCart();
  updateCartCount();
  updateCartDisplay();
  showSection("home");
});

document.addEventListener("DOMContentLoaded", function() {
    showSection('home');
});

const style = document.createElement("style");
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
`;
document.head.appendChild(style);


window.addEventListener("scroll", () => {
  const header = document.querySelector(".header");
  const headerContent = document.querySelector(".header-content");
  const profileBtn = document.querySelector(".profile-btn");
  if (window.scrollY > 50) {
    header.classList.add("shrink");
    headerContent.classList.add("shrink");
    profileBtn.classList.add("shrink");
  } else {
    header.classList.remove("shrink");
    headerContent.classList.remove("shrink");
    profileBtn.classList.remove("shrink");
  }
});
window.addEventListener("scroll", () => {
  const header = document.querySelector(".header");
  const headerContent = document.querySelector(".header-content");
  if (window.scrollY > 50) {
    header.classList.add("shrink");
    headerContent.classList.add("shrink");
  } else {
    header.classList.remove("shrink");
    headerContent.classList.remove("shrink");
  }
});


function handleViewProduct(id, name, price, description, image) {
  if (!isLoggedIn) {
    showLoginModal();
    return;
  }
  currentProduct = { id, name, price, description, image };


  let grandePrice = price;
  let supremePrice = price;
  if (
    id.startsWith("ameri") ||
    id.startsWith("caramel-macchiato") ||
    id.startsWith("spanish-latte") ||
    id.startsWith("vanilla-latte") ||
    id.startsWith("mocha") ||
    id.startsWith("white") ||
    id.startsWith("salted-caramel")
  ) {
    grandePrice = 120;
    supremePrice = 150;
  }
  // Specialty Coffee
  else if (
    id.startsWith("ube") ||
    id.startsWith("honey") ||
    id.startsWith("dolce") ||
    id.startsWith("cacao") ||
    id.startsWith("cafe-con-leche") ||
    id.startsWith("sea-salt-mocha") ||
    id.startsWith("creamy-pistachio-latte") ||
    id.startsWith("peppermint-mocha")
  ) {
    grandePrice = 150;
    supremePrice = 180;
  }
  // Chocolate Overload
  else if (
    id.startsWith("toblerone-kick") ||
    id.startsWith("kisses") ||
    id.startsWith("oreo") ||
    id.startsWith("kitkat-break") ||
    id.startsWith("mms-burst")
  ) {
    grandePrice = 150;
    supremePrice = 180;
  }
  // Matcha Series
  else if (
    id.startsWith("matcha-latte") ||
    id.startsWith("white-choco-matcha") ||
    id.startsWith("berry-matcha") ||
    id.startsWith("dirty-matcha")
  ) {
    grandePrice = 160;
    supremePrice = 190;
  }
  // Milk Based
  else if (
    id.startsWith("strawberry-cloud") ||
    id.startsWith("minty-choco") ||
    id.startsWith("white-chocolate")
  ) {
    grandePrice = 99;
    supremePrice = 120;
  }
  // All Time Fave
  else if (
    id.startsWith("milo-dinosaur") ||
    id.startsWith("ube-cloud")
  ) {
    grandePrice = 99;
    supremePrice = 120;
  }
  // Default fallback
  else {
    grandePrice = price;
    supremePrice = price;
  }

  // Set currentProduct.price based on selectedSize
  currentProduct.price = selectedSize === "Grande" ? grandePrice : supremePrice;

  // Show only the price for the selected size
  let priceText = "";
  if (selectedSize === "Grande") {
    priceText = `Php ${grandePrice} (Grande)`;
  } else {
    priceText = `Php ${supremePrice} (Supreme)`;
  }
  document.getElementById("modalProductName").textContent = name
  document.getElementById("modalProductPrice").textContent = priceText
  document.getElementById("modalProductDescription").textContent = description
  document.getElementById("modalProductImage").src = image
  document.getElementById("modalProductImage").alt = name

  // Reset size selection and set click events for both buttons
  document.querySelectorAll(".size-btn").forEach((btn) => {
    btn.classList.remove("active");
    if (btn.textContent.trim() === selectedSize) btn.classList.add("active");
    btn.onclick = function () {
      selectSize(btn.textContent.trim(), grandePrice, supremePrice, name);
    };
  });

 
  const modal = document.getElementById("productModal");
  modal.style.display = "flex";
  modal.style.alignItems = "center";
  modal.style.justifyContent = "center";
  modal.style.position = "fixed";
  modal.style.top = "0";
  modal.style.left = "0";
  modal.style.width = "100vw";
  modal.style.height = "100vh";
  modal.style.background = "rgba(0,0,0,0.15)";
  modal.style.zIndex = "3000";
  modal.classList.add("active");
  document.body.style.overflow = "hidden";




  window.scrollTo({ top: 0, behavior: "auto" });
  let pickupForm = document.getElementById("pickupFormModal");
  if (pickupForm) pickupForm.remove();
  const detailsSection = document.querySelector(".product-modal-details");
  const addBtn = detailsSection.querySelector(".product-modal-add-cart");
  if (addBtn) {
    
    const newBtn = addBtn.cloneNode(true);
    addBtn.parentNode.replaceChild(newBtn, addBtn);
    detailsSection.appendChild(newBtn);
    newBtn.onclick = function () {
      addProductToCart();
      modal.classList.remove("active");
      modal.style.display = "none";
      document.body.style.overflow = "auto";
    };
  }
}

// Update price when size is changed
function selectSize(size, grandePrice, supremePrice, name) {
  selectedSize = size;
  document.querySelectorAll(".size-btn").forEach((btn) => {
    btn.classList.remove("active");
    if (btn.textContent.trim() === size) btn.classList.add("active");
  });
  // Update price and modal content for the selected size, keep name
  if (currentProduct) {
    let price = size === "Grande" ? (grandePrice || currentProduct.price) : (supremePrice || currentProduct.price);
    currentProduct.price = price;
    let priceText = size === "Grande"
      ? `Php ${grandePrice || currentProduct.price} (Grande)`
      : `Php ${supremePrice || currentProduct.price} (Supreme)`;
    document.getElementById("modalProductPrice").textContent = priceText;
    document.getElementById("modalProductName").textContent = name || currentProduct.name;
  }
}

// Handle checkout button click
function handleCheckout() {
  const deliveryOptions = document.getElementById("deliveryOptions");
  if (!deliveryOptions || deliveryOptions.style.display !== "block") {
    startCheckout();
    return;
  }

  const pickup_name = document.getElementById("pickupName") ? document.getElementById("pickupName").value : "";
  const pickup_location = document.getElementById("pickupLocation") ? document.getElementById("pickupLocation").value : "";
  const pickup_time = document.getElementById("pickupTime") ? document.getElementById("pickupTime").value : "";
  const special_instructions = document.getElementById("specialInstructions") ? document.getElementById("specialInstructions").value : "";

 if (pickup_time) {
  const [h, m] = pickup_time.split(":").map(Number);
  const mins = h * 60 + m;
  const open = 15 * 60;       
  const close = 20 * 60 + 30; 

  if (mins < open || mins > close) {
    showNotification("Pickup time must be between 3:00 p.m and 8:30 p.m.", "error");
    return;
  }
}


  if (!pickup_name || !pickup_location || !pickup_time) {
    showNotification("Please fill out all required pickup details.", "error");
    return;
  }

  fetch('pickup_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:
      `pickup_name=${encodeURIComponent(pickup_name)}` +
      `&pickup_location=${encodeURIComponent(pickup_location)}` +
      `&pickup_time=${encodeURIComponent(pickup_time)}` +
      `&special_instructions=${encodeURIComponent(special_instructions)}` +
      `&cart_items=${encodeURIComponent(JSON.stringify(cart))}`
  })
  .then(res => res.text())
  .then(text => {
    console.log('Pickup checkout raw response:', text);
    if (!text.trim()) {
      showNotification("Empty server response.", "error");
      return;
    }

    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      showNotification("Invalid server response: " + text, "error");
      return;
    }

    if (data.success) {
      showNotification("Pickup order placed successfully!", "success");
      closeCart();
      cart = [];
      updateCartCount();
      updateCartDisplay();

      if (typeof showReceiptModal === "function" && data.reference_number) {
        showReceiptModal(data.reference_number);
      }
    } else {
      showNotification(data.message || "Pickup order failed.", "error");
    }
  })
  .catch((err) => {
    console.error('Pickup checkout error:', err);
    showNotification("Network error. Please try again.", "error");
  });
}

function handlePickupCheckout() {
  const pickup_name = document.getElementById("pickupName").value;
  const pickup_location = document.getElementById("pickupLocation").value;
  const pickup_time = document.getElementById("pickupTime").value;
  const special_instructions = document.getElementById("specialInstructions").value;

  fetch('pickup_checkout.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `pickup_name=${encodeURIComponent(pickup_name)}&pickup_location=${encodeURIComponent(pickup_location)}&pickup_time=${encodeURIComponent(pickup_time)}&special_instructions=${encodeURIComponent(special_instructions)}`
  })
  .then(res => {
    console.log('Pickup checkout response status:', res.status);
    return res.text().then(text => {
      console.log('Pickup checkout raw response:', text);
      try {
        return JSON.parse(text);
      } catch (e) {
        showNotification("Invalid server response.", "error");
        throw e;
      }
    });
  })
  .then(data => {
    if (data.success) {
      showNotification("Pickup order placed successfully!", "success");
      closeCart();
      cart = [];
      updateCartCount();
      updateCartDisplay();
    } else {
      showNotification(data.message || "Pickup order failed.", "error");
    }
  })
  .catch((err) => {
    console.error('Pickup checkout error:', err);
    showNotification("Network error. Please try again.", "error");
  });
}

// Registration form validation
function validateRegisterForm() {
    const registerName = document.getElementById("registerName");
    const registerLastName = document.getElementById("registerLastName");
    const registerEmail = document.getElementById("registerEmail");
    const registerPassword = document.getElementById("registerPassword");
    const confirmPassword = document.getElementById("confirmPassword");
    const firstnameError = document.getElementById("firstnameError");
    const lastnameError = document.getElementById("lastnameError");
    const emailError = document.getElementById("emailError");
    const passwordError = document.getElementById("passwordError");

    let valid = true;

    // Check for empty fields
    if (!registerName.value.trim()) {
        firstnameError.textContent = "First name is required.";
        valid = false;
    }
    if (!registerLastName.value.trim()) {
        lastnameError.textContent = "Last name is required.";
        valid = false;
    }
    if (!registerEmail.value.trim()) {
        emailError.textContent = "Email is required.";
        valid = false;
    }
    if (!registerPassword.value.trim()) {
        passwordError.textContent = "Password is required.";
        valid = false;
    }
    if (!confirmPassword.value.trim()) {
        passwordError.textContent = "Please confirm your password.";
        valid = false;
    }

    // Password length
    if (registerPassword.value.length > 0 && registerPassword.value.length < 8) {
        passwordError.textContent = "Password must be at least 8 characters.";
        valid = false;
    }

    // Password match
    if (registerPassword.value && confirmPassword.value && registerPassword.value !== confirmPassword.value) {
        passwordError.textContent = "Passwords do not match.";
        valid = false;
    }

    if (firstnameError.textContent && firstnameError.textContent !== "First name is required.") valid = false;
    if (lastnameError.textContent && lastnameError.textContent !== "Last name is required.") valid = false;
    if (emailError.textContent && emailError.textContent !== "Email is required.") valid = false;
    return valid;
}


if (document.getElementById('registerForm')) {
    document.getElementById('registerForm').addEventListener('submit', function(e) {
        document.getElementById("firstnameError").textContent = "";
        document.getElementById("lastnameError").textContent = "";
        document.getElementById("emailError").textContent = "";
        document.getElementById("passwordError").textContent = "";
        if (!validateRegisterForm()) {
            e.preventDefault();
            return false;
        }
    });
}

// Terms and Conditions modal logic
window.addEventListener("DOMContentLoaded", function() {
    var showTermsBtn = document.getElementById('showTermsBtn');
    var termsModal = document.getElementById('termsModal');
    if (showTermsBtn && termsModal) {
        showTermsBtn.onclick = function(e) {
            e.preventDefault();
            termsModal.classList.add('active');
        };
    }
});

// AJAX check for fullname/email 
window.addEventListener("DOMContentLoaded", function() {
    const registerName = document.getElementById("registerName");
    const registerEmail = document.getElementById("registerEmail");
    const registerLastName = document.getElementById("registerLastName");
    const firstnameError = document.getElementById("firstnameError");
    const emailError = document.getElementById("emailError");
    const lastnameError = document.getElementById("lastnameError");

    if (registerName) {
        registerName.addEventListener("blur", function () {
            const name = registerName.value.trim();
            if (!name) return;
            fetch('AJAX/check_duplicates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `field=user_FN&value=${encodeURIComponent(name)}`
            })
                .then(res => res.json())
                .then(data => {
                    if (data.exists) {
                        firstnameError.textContent = "First name is already registered.";
                    } else {
                        firstnameError.textContent = "";
                    }
                })
                .catch(err => {
                    firstnameError.textContent = "An error occurred. Please try again.";
                });
        });
    }

    if (registerEmail) {
        registerEmail.addEventListener("blur", function() {
            const email = registerEmail.value.trim();
            if (!email) return;
            fetch('AJAX/check_duplicates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `field=user_email&value=${encodeURIComponent(email)}`
            })
                .then(res => res.json())
                .then(data => {
                    if (data.exists) {
                        emailError.textContent = "Email is already registered.";
                    } else {
                        emailError.textContent = "";
                    }
                })
                .catch(err => {
                    emailError.textContent = "An error occurred. Please try again.";
                });
        }); 
    }

    if (registerLastName) {
        registerLastName.addEventListener("blur", function () {
            const lastName = registerLastName.value.trim();
            if (!lastName) return;
            fetch('AJAX/check_duplicates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `field=user_LN&value=${encodeURIComponent(lastName)}`
            })
                .then(res => res.json())
                .then(data => {
                    if (data.exists) {
                        lastnameError.textContent = "Last name is already registered.";
                    } else {
                        lastnameError.textContent = "";
                    }
                })
                .catch(err => {
                    lastnameError.textContent = "An error occurred. Please try again.";
                });
        });
    }
});

// === Edit Profile Modal Logic ===
function showEditProfileModal() {
  document.getElementById('editProfileFN').value = window.PHP_USER_FN || '';
  document.getElementById('editProfileLN').value = window.PHP_USER_LN || '';
  document.getElementById('editProfileEmail').value = window.PHP_USER_EMAIL || '';
  document.getElementById('editProfilePassword').value = '';
  document.getElementById('editProfileSuccess').style.display = 'none';
  document.getElementById('editProfileError').style.display = 'none';
  document.getElementById('editProfileModal').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeEditProfileModal() {
  document.getElementById('editProfileModal').classList.remove('active');
  document.body.style.overflow = 'auto';
  document.getElementById('editProfileForm').reset();
  document.getElementById('editProfileSuccess').style.display = 'none';
  document.getElementById('editProfileError').style.display = 'none';
}

function handleEditProfile(event) {
  event.preventDefault();
  const btn = document.getElementById('editProfileBtn');
  btn.disabled = true;
  btn.classList.add('loading');
  const FN = document.getElementById('editProfileFN').value.trim();
  const LN = document.getElementById('editProfileLN').value.trim();
  const email = document.getElementById('editProfileEmail').value.trim();
  const password = document.getElementById('editProfilePassword').value;
  const data = {
    user_FN: FN,
    user_LN: LN,
    user_email: email
  };
  if (password && password.length >= 8) data.user_password = password;
  fetch('AJAX/update_user_info.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
    credentials: 'same-origin'
  })
    .then(res => res.json())
    .then(result => {
      if (result.success) {
        document.getElementById('editProfileSuccess').textContent = result.message || 'Profile updated!';
        document.getElementById('editProfileSuccess').style.display = 'block';
        document.getElementById('editProfileError').style.display = 'none';
        window.PHP_USER_FN = FN;
        window.PHP_USER_LN = LN;
        window.PHP_USER_EMAIL = email;
        setTimeout(() => {
          closeEditProfileModal();
          showNotification('Profile updated!', 'success');
        }, 1200);
      } else {
        document.getElementById('editProfileError').textContent = result.message || 'Update failed.';
        document.getElementById('editProfileError').style.display = 'block';
        document.getElementById('editProfileSuccess').style.display = 'none';
      }
    })
    .catch(() => {
      document.getElementById('editProfileError').textContent = 'An error occurred.';
      document.getElementById('editProfileError').style.display = 'block';
      document.getElementById('editProfileSuccess').style.display = 'none';
    })
    .finally(() => {
      btn.disabled = false;
      btn.classList.remove('loading');
    });
}



