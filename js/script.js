window.toggleProfileDropdown = function (event) {
  event.stopPropagation();
  var menu = document.getElementById("profileDropdownMenu");
  if (menu) {
    menu.classList.toggle("show");
  }
};


let __pendingRegistration = null;
let __otpVerified = false;
let __registrationCompleted = false;

function showSection(sectionName) {
  try {
    document.querySelectorAll('.section-content').forEach(s => {
      s.style.display = 'none';
      s.classList.remove('active');
    });
    const target = document.getElementById(sectionName);
    if (target) {
      target.style.display = 'block';
      target.classList.add('active');
      window.scrollTo(0, 0);
    }
    // best-effort nav highlight
    try {
      document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
      const match = Array.from(document.querySelectorAll('.nav-item')).find(n =>
        (n.dataset && n.dataset.section && n.dataset.section === sectionName) ||
        (n.textContent || '').toLowerCase().trim() === sectionName.toLowerCase().trim()
      );
      if (match) match.classList.add('active');
    } catch (e) { /* ignore */ }
  } catch (e) {
    console && console.warn && console.warn('showSection error', e);
  }
}
// ensure global exposure (redundant if declared at top-level but explicit is fine)

window.showSection = showSection;
// Ensure a global default sugar selection
window.selectedSugar = window.selectedSugar || 'Less Sweet';
let cart = []
let currentSection = "home"
let isLoggedIn = false
let currentUser = null
let deliveryMethod = "pickup"
let selectedSize = "Grande"
let currentProduct = null
let lastDrinkType = 'cold';
let modalSelectedToppings = {};
let __lastFocusedBeforePaymentModal = null;

const TOPPINGS = [
  { key: 'extra_shot', name: 'Extra shot (coffee)', price: 40 },
  { key: 'oatmilk', name: 'Oatmilk', price: 50 },
  { key: 'extra_sauce', name: 'Extra sauce (milk-based)', price: 20 },
  { key: 'whipped_cream', name: 'Additional whipped cream', price: 20 }
];

function selectSugar(level) {
  const normalized = (level || '').toString().trim();
  document.querySelectorAll('.sugar-btn').forEach(btn => btn.classList.remove('active'));
  const btnMatch = Array.from(document.querySelectorAll('.sugar-btn'))
    .find(b => (b.dataset.sugar || b.textContent).trim().toLowerCase() === normalized.toLowerCase());
  if (btnMatch) btnMatch.classList.add('active');
  window.selectedSugar = normalized || 'Less Sweet';
  if (currentProduct) currentProduct.sugar = window.selectedSugar;
  if (typeof recalcModalTotal === 'function') recalcModalTotal();
}

function recalcModalTotal() {
  if (!currentProduct) return;

  // determine base price for the currently selected size/variant
  let base = Number(currentProduct.price || 0);
  if (currentProduct.dataType !== 'pastries') {
    base = selectedSize === 'Grande'
      ? Number(currentProduct.grandePrice ?? base)
      : Number(currentProduct.supremePrice ?? base);
  } else if (currentProduct.dataType === 'pastries' && currentProduct.variants) {
    base = Number(currentProduct.price ?? base);
  }

  // sum toppings
  let toppingsSum = 0;
  Object.values(modalSelectedToppings || {}).forEach(t => {
    const qty = Number(t.qty || t.quantity || 1);
    toppingsSum += Number(t.price || 0) * qty;
  });

  const total = base + toppingsSum;

  // update modal total (base + toppings)
  const totalEl = document.getElementById('modalTotalAmount');
  if (totalEl) totalEl.textContent = Number(total).toFixed(2);

  // update product base price display (do NOT overwrite with total)
  const priceEl = document.getElementById('modalProductPrice');
  if (priceEl) priceEl.textContent = `Php ${Number(base).toFixed(2)}`;

  // update variant label if present
  const pv = document.getElementById('modalPriceVariant');
  if (pv) pv.textContent = `(${selectedSize})`;
}

// ...existing code...

document.querySelectorAll('.view-btn').forEach(button => {
  button.addEventListener('click', handleViewProduct);
});
function handleViewProduct(event) {
  if (!isLoggedIn) {
    showLoginModal();
    return;
  }
  // proceed to open product modal
  openProductModal(event.target.dataset.productId);
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
// ...existing code...

document.querySelectorAll('.view-btn').forEach(button => {
  button.addEventListener('click', handleViewProduct);
});
function handleViewProduct(event) {
  if (!isLoggedIn) {
    showLoginModal();
    return;
  }
  // proceed to open product modal
  openProductModal(event.target.dataset.productId);
}

// Close modal helper
function closeProductModal() {
  const modal = document.getElementById('productModal');
  if (modal) {
    modal.style.display = 'none';
    modal.classList.remove('active');
    document.body.style.overflow = '';
  }
}

// close modal on backdrop click
document.getElementById('productModal')?.addEventListener('click', function (e) {
  if (e.target === this) closeProductModal();
});


function selectSize(size) {
  selectedSize = size;
  document.querySelectorAll(".size-btn").forEach((btn) => {
    btn.classList.remove("active");
    if (btn.textContent.trim() === size) btn.classList.add("active");
  });
}






// Add sugar-selection helper to support inline onclick or programmatic calls
function selectSugar(level) {
  // Normalize level (accept 'Less', 'Less Sweet', etc.)
  const normalized = (level || '').toString().trim().toLowerCase();
  document.querySelectorAll('.sugar-btn').forEach(btn => btn.classList.remove('active'));
  const found = Array.from(document.querySelectorAll('.sugar-btn')).find(btn => {
    const ds = (btn.dataset.sugar || btn.textContent || '').toString().trim().toLowerCase();
    return ds === normalized || ds.startsWith(normalized);
  });
  if (found) found.classList.add('active');
  // store selected sugar if you need it later
  window.selectedSugar = found ? (found.dataset.sugar || found.textContent.trim()) : (level || '');
  if (typeof recalcModalTotal === 'function') recalcModalTotal();
}

// Ensure delegated sugar button clicks are exclusive (if you already have a sugar click handler, this keeps behavior)
document.addEventListener('click', function (e) {
  const sugarBtn = e.target.closest('.sugar-btn');
  if (sugarBtn) {
    document.querySelectorAll('.sugar-btn').forEach(b => b.classList.remove('active'));
    sugarBtn.classList.add('active');
    window.selectedSugar = sugarBtn.dataset.sugar || sugarBtn.textContent.trim();
    if (typeof recalcModalTotal === 'function') recalcModalTotal();
    return;
  }
});

function addProductToCart() {
  if (!currentProduct) return;

  let base = Number(currentProduct.price || 0);
  if (currentProduct.dataType !== 'pastries') {
    base = selectedSize === 'Grande'
      ? Number(currentProduct.grandePrice || base)
      : Number(currentProduct.supremePrice || base);
  } else if (currentProduct.dataType === 'pastries' && currentProduct.variants) {
    base = Number(currentProduct.price || base);
  }

  const toppingsArr = Object.keys(modalSelectedToppings || {}).map(k => {
    const t = modalSelectedToppings[k];
    return {
      id: k,
      name: t.name,
      price: Number(t.price || 0),
      quantity: Number(t.qty || 1)
    };
  });
  const toppingsSum = toppingsArr.reduce((s, t) => s + (t.price * t.quantity), 0);
  const itemPrice = Number((base + toppingsSum).toFixed(2));
  const sugarLevel = currentProduct.sugar || window.selectedSugar || 'Less Sweet';

  const item = {
    product_id: currentProduct.id || ('manual-' + (currentProduct.name || '').replace(/\s+/g, '-').toLowerCase()),
    name: `${currentProduct.name} (${selectedSize})`,
    basePrice: base,
    price: itemPrice,
    quantity: 1,
    size: selectedSize,
    sugar: sugarLevel,             // NEW: include sugar level in cart item
    toppings: toppingsArr,
    description: currentProduct.description || ''
  };

  addToCart(item);
  closeProductModal();
  showNotification("Product added to cart!", "success");
}
// ...existing code...
// small delegated handlers to match the new button UI in the modal
document.addEventListener('click', function (e) {
  // size buttons
  const sizeBtn = e.target.closest('.size-btn');
  if (sizeBtn) {
    document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
    sizeBtn.classList.add('active');
    selectedSize = sizeBtn.dataset.size || sizeBtn.textContent.trim();
    // update variant label if present
    const pv = document.getElementById('modalPriceVariant');
    if (pv) pv.textContent = `(${selectedSize})`;
    if (window.recalcModalTotal) window.recalcModalTotal();
    return;
  }

  // sugar buttons (visual only)
  const sugarBtn = e.target.closest('.sugar-btn');
  if (sugarBtn) {
    document.querySelectorAll('.sugar-btn').forEach(b => b.classList.remove('active'));
    sugarBtn.classList.add('active');
    if (window.recalcModalTotal) window.recalcModalTotal();
    return;
  }
  const addonBtn = e.target.closest('.add-on-btn');
  if (addonBtn) {
    addonBtn.classList.toggle('active');
    const key = addonBtn.dataset.key;
    const price = parseFloat(addonBtn.dataset.price || 0);

    // ensure we use the module-level modalSelectedToppings object
    if (!modalSelectedToppings) modalSelectedToppings = {};
    if (addonBtn.classList.contains('active')) {
      modalSelectedToppings[key] = { name: addonBtn.querySelector('span')?.textContent.trim() || key, price: price, qty: 1 };
    } else {
      delete modalSelectedToppings[key];
    }

    if (window.recalcModalTotal) window.recalcModalTotal();
    else {
      // fallback simple total update if recalcModalTotal not defined
      const base = parseFloat((document.getElementById('modalProductPrice')?.textContent || '0').replace(/[^0-9.]/g, '')) || 0;
      let addons = 0;
      Object.values(modalSelectedToppings || {}).forEach(t => addons += (t.price || 0));
      document.getElementById('modalTotalAmount').textContent = (base + addons).toFixed(2);
    }
    return;
  }
});
// ...existing code...

function addToCart(product_id, name, price, size) {
  if (typeof product_id === 'object') {
    const itemObj = product_id;
    const existing = cart.find(it => it.product_id === itemObj.product_id && it.size === itemObj.size && JSON.stringify(it.toppings || []) === JSON.stringify(itemObj.toppings || []));
    if (existing) existing.quantity += (itemObj.quantity || 1);
    else cart.push(Object.assign({ quantity: itemObj.quantity || 1 }, itemObj));
  } else {
    const existingItem = cart.find((item) =>
      item.product_id === product_id && item.name === name && item.size === size
    );
    if (existingItem) {
      existingItem.quantity += 1;
    } else {
      cart.push({
        product_id: product_id,
        name: name,
        price: Number(price || 0),
        basePrice: Number(price || 0),
        quantity: 1,
        size: size,
        toppings: []
      });
    }
  }
  updateCartCount();
  updateCartDisplay();

  // Safe UI feedback (no undefined 'event' usage)
  showNotification("Added to cart", "success");
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

function sendOTP() {
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
// Update the updateCartDisplay function to use button styling
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
          <div class="cart-item-details">
            <div class="cart-item-name">${item.name}</div>
            <div class="cart-item-price">₱${Number((item.basePrice || item.price)).toFixed(2)}</div>
            ${Array.isArray(item.toppings) && item.toppings.length ? `
              <div class="cart-item-toppings">
                ${item.toppings.map(t => `
                  <div class="selected-topping">
                    ${t.name.replace(/\s*—.*$/, '')} (₱${Number(t.price).toFixed(2)}${t.quantity > 1 ? ` x${t.quantity}` : ''})
                  </div>
                `).join('')}
              </div>
            ` : ''}
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

  // Update total calculation
  const total = cart.reduce((sum, item) => {
    const base = Number(item.basePrice || item.price || 0);
    const toppingsSum = (item.toppings || []).reduce((s, t) => s + (Number(t.price || 0) * (Number(t.quantity || 1))), 0);
    return sum + (base + toppingsSum) * Number(item.quantity || 1);
  }, 0);

  cartTotalContainer.innerHTML = `
    <div class="total-amount">Total: ₱${total.toFixed(2)}</div>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <button class="checkout-btn" id="cartCheckoutBtn">
        <i class="fas fa-credit-card"></i> Checkout
      </button>
    </div>
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


// close helper updated to restore focus
function closePaymentModal() {
  const paymentModal = document.getElementById("paymentMethodModal");
  if (!paymentModal) return;
  paymentModal.classList.remove("open");
  paymentModal.setAttribute("aria-hidden", "true");
  document.body.style.overflow = "auto";

  // hide GCASH preview to reset state
  const gcashPreview = document.getElementById("gcashPreview");
  if (gcashPreview) gcashPreview.style.display = "none";

  // restore focus to previously focused element if safe
  try {
    if (__lastFocusedBeforePaymentModal && typeof __lastFocusedBeforePaymentModal.focus === 'function') {
      __lastFocusedBeforePaymentModal.focus();
    } else {
      // fallback: focus checkout button in cart
      const checkout = document.querySelector('.checkout-btn');
      if (checkout) checkout.focus();
    }
  } catch (e) { /* ignore focus restore errors */ }
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
// Replace the existing completePickupCheckout function with this:
function completePickupCheckout() {
  const pickup_name = document.getElementById("pickupName").value;
  const pickup_location = document.getElementById("pickupLocation").value;
  const pickup_time = document.getElementById("pickupTime").value;
  const special_instructions = document.getElementById("specialInstructions").value;

  // Before submitting pickup details, show payment method modal
  const paymentModal = document.getElementById('paymentMethodModal');
  if (paymentModal) {
    // store current pickup details temporarily
    paymentModal.dataset.pickupName = pickup_name;
    paymentModal.dataset.pickupLocation = pickup_location;
    paymentModal.dataset.pickupTime = pickup_time;
    paymentModal.dataset.specialInstructions = special_instructions;
    paymentModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  } else {
    // fallback: submit directly
    submitPickupForm({ pickup_name, pickup_location, pickup_time, special_instructions, payment_method: 'cash' });
  }
}

// Add these new functions:
function handlePaymentChoice(method) {
  const paymentModal = document.getElementById('paymentMethodModal');
  if (!paymentModal) return;
  if (method === 'cash') {
    // close payment modal and proceed to placing pickup details
    paymentModal.style.display = 'none';
    document.body.style.overflow = 'auto';
    const pickup = {
      pickup_name: paymentModal.dataset.pickupName || '',
      pickup_location: paymentModal.dataset.pickupLocation || '',
      pickup_time: paymentModal.dataset.pickupTime || '',
      special_instructions: paymentModal.dataset.specialInstructions || ''
    };
    submitPickupForm({ ...pickup, payment_method: 'cash' });
    return;
  }
  if (method === 'gcash') {
    // show gcash preview inside modal
    const preview = document.getElementById('gcashPreview');
    if (preview) preview.style.display = 'block';
  }
}

function submitGcashCheckout() {
  const paymentModal = document.getElementById('paymentMethodModal');
  if (!paymentModal) return;
  const pickup = {
    pickup_name: paymentModal.dataset.pickupName || '',
    pickup_location: paymentModal.dataset.pickupLocation || '',
    pickup_time: paymentModal.dataset.pickupTime || '',
    special_instructions: paymentModal.dataset.specialInstructions || ''
  };
  // submit with payment_method=gcash
  paymentModal.style.display = 'none';
  document.body.style.overflow = 'auto';
  submitPickupForm({ ...pickup, payment_method: 'gcash' });
}

// Ensure submitPickupForm always sends cash regardless of payload
function submitPickupForm(payload) {
  payload = payload || {};
  payload.payment_method = 'cash';

  try { console.debug("submitPickupForm payload (cash-only):", payload); } catch (e) { }

  const cart_items = JSON.stringify(cart || []);
  const body = new URLSearchParams();
  body.append("pickup_name", payload.pickup_name || "");
  body.append("pickup_location", payload.pickup_location || "");
  body.append("pickup_time", payload.pickup_time || "");
  body.append("special_instructions", payload.special_instructions || "");
  body.append("payment_method", "cash");
  body.append("cart_items", cart_items);

  showNotification("Placing order (cash)...", "success");
  fetch("pickup_checkout.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body.toString(),
    credentials: "same-origin"
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        showNotification("Order placed!", "success");
        if (data.reference_number) showReferenceModal(data.reference_number);
        cart = [];
        updateCartCount();
        updateCartDisplay();
        closeCart();
      } else {
        showNotification(data.message || "Failed to place order.", "error");
      }
    })
    .catch(err => {
      console.error("pickup submit error", err);
      showNotification("Network error. Please try again.", "error");
    });
}

document.addEventListener("DOMContentLoaded", function () {
  // Checkout buttons (cart total area)
  document.body.addEventListener('click', function (ev) {
    const checkoutBtn = ev.target.closest && ev.target.closest('.checkout-btn');
    if (checkoutBtn) {
      ev.preventDefault && ev.preventDefault();
      // debounce guard to prevent double/spam clicks
      if (checkoutBtn.dataset.processing === '1') return;
      checkoutBtn.dataset.processing = '1';
      setTimeout(() => { delete checkoutBtn.dataset.processing; }, 700);
      handleCheckout();
      return;
    }

    // inline payment handlers
    if (ev.target && ev.target.id === 'payCashInlineBtn') {
      const cartTotal = document.getElementById('cartTotal');
      const payload = {
        pickup_name: cartTotal?.dataset?.pickupName || '',
        pickup_location: cartTotal?.dataset?.pickupLocation || '',
        pickup_time: cartTotal?.dataset?.pickupTime || '',
        special_instructions: cartTotal?.dataset?.specialInstructions || '',
        payment_method: 'cash'
      };
      const inline = document.getElementById("paymentChoicesInline");
      if (inline) inline.style.display = "none";
      submitPickupForm(payload);
      return;
    }

    if (ev.target && ev.target.id === 'payGcashInlineBtn') {
      const gcashInline = document.getElementById('gcashPreviewInline');
      if (gcashInline) gcashInline.style.display = 'block';
      return;
    }

    if (ev.target && ev.target.id === 'gcashDoneInlineBtn') {
      const cartTotal = document.getElementById('cartTotal');
      const payload = {
        pickup_name: cartTotal?.dataset?.pickupName || '',
        pickup_location: cartTotal?.dataset?.pickupLocation || '',
        pickup_time: cartTotal?.dataset?.pickupTime || '',
        special_instructions: cartTotal?.dataset?.specialInstructions || '',
        payment_method: 'gcash'
      };
      const inline = document.getElementById("paymentChoicesInline");
      if (inline) inline.style.display = "none";
      submitPickupForm(payload);
      return;
    }
  });



  // Also wire GCash Done button if it exists (modal flow)
  const gcashDone = document.getElementById("gcashDoneBtn");
  if (gcashDone) {
    gcashDone.removeEventListener("click", submitGcashCheckout);
    gcashDone.addEventListener("click", function (e) {
      e.preventDefault && e.preventDefault();
      submitGcashCheckout();
    });
  }

  // Close payment modal when clicking outside content
  const paymentModal = document.getElementById("paymentMethodModal");
  if (paymentModal) {
    paymentModal.addEventListener("click", function (ev) {
      if (ev.target === paymentModal) {
        paymentModal.style.display = "none";
        document.body.style.overflow = "auto";
      }
    });
  }
});

async function setPromoActive(id, value) {
  const body = new URLSearchParams();
  body.append('id', id);
  if (typeof value !== 'undefined') body.append('active', value ? '1' : '0'); // set explicitly
  const res = await fetch('admin/AJAX/toggle_promo.php', { method: 'POST', body });
  const data = await res.json();
  return data;
}

// example usage: set inactive
setPromoActive(14, 0).then(d => console.log(d));
(function () {
  const hamb = document.querySelector('.hamburger-menu');
  const nav = document.querySelector('.nav-menu');

  if (!hamb || !nav) return;

  // Toggle nav open/close (use 'mobile-open' to match CSS)
  hamb.addEventListener('click', function (e) {
    e.stopPropagation();
    const isOpen = nav.classList.toggle('mobile-open'); // <-- changed
    hamb.classList.toggle('open', isOpen); // keep this for button styling
    hamb.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    // move focus into nav when opened for accessibility
    if (isOpen) {
      const firstLink = nav.querySelector('.nav-item');
      if (firstLink && typeof firstLink.focus === 'function') firstLink.focus();
    }
  });

  // Close nav when a nav-item is clicked and navigate to section
  nav.addEventListener('click', function (e) {
    const item = e.target.closest('.nav-item');
    if (!item) return;
    e.preventDefault && e.preventDefault();

    const sectionKey = (item.dataset && item.dataset.section) ? item.dataset.section.trim() : (item.textContent || '').trim().toLowerCase();
    const map = {
      home: 'home',
      about: 'about',
      shop: 'products',
      menu: 'products',
      products: 'products',
      locations: 'locations',
      promos: 'promos',
      contact: 'contact'
    };
    const target = map[sectionKey] || sectionKey;

    if (typeof showSection === 'function') showSection(target);
    else {
      const el = document.getElementById(target);
      if (el) {
        document.querySelectorAll('.section-content').forEach(s => { s.style.display = 'none'; s.classList.remove('active'); });
        el.style.display = 'block';
        el.classList.add('active');
        window.scrollTo(0, 0);
      }
    }

    // close the mobile menu after navigation
    nav.classList.remove('mobile-open'); // <-- changed
    hamb.classList.remove('open');
    hamb.setAttribute('aria-expanded', 'false');
  });

  // Close nav when clicking outside
  document.addEventListener('click', function (e) {
    if (!nav.classList.contains('mobile-open')) return; // <-- changed
    if (e.target === nav || nav.contains(e.target) || e.target === hamb || hamb.contains(e.target)) return;
    nav.classList.remove('mobile-open'); // <-- changed
    hamb.classList.remove('open');
    hamb.setAttribute('aria-expanded', 'false');
  });

  // Close nav with Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('mobile-open')) { // <-- changed
      nav.classList.remove('mobile-open'); // <-- changed
      hamb.classList.remove('open');
      hamb.setAttribute('aria-expanded', 'false');
      hamb.focus();
    }
  });
})();

function showReferenceModal(ref) {
  // Remove old modal if present
  let existing = document.getElementById('orderReferenceModal');
  if (existing) existing.remove();

  // Build modal
  const modal = document.createElement('div');
  modal.id = 'orderReferenceModal';
  modal.style.cssText = `
      position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
      background: rgba(0,0,0,0.35); z-index: 10050; padding: 20px;
    `;

  modal.innerHTML = `
      <div style="width:100%;max-width:520px;background:#fff;border-radius:14px;padding:22px;box-shadow:0 18px 50px rgba(0,0,0,0.2);text-align:center;position:relative;">
        <button id="orderRefClose" aria-label="Close" style="position:absolute;right:12px;top:12px;border:none;background:transparent;font-size:22px;cursor:pointer;color:#374151;">&times;</button>
        <div style="font-size:14px;color:#10B981;font-weight:800;margin-bottom:8px;">
          Order placed successfully
        </div>
        <h2 style="margin:6px 0 4px;color:#23433a;font-size:1.6rem;">Reference number</h2>
        <div id="orderRefValue" style="margin:14px 0;padding:14px;border-radius:10px;background:#f6faf8;border:1px dashed #e6efe9;font-weight:800;font-size:1.2rem;color:#114032;word-break:break-all;">
          ${String(ref || 'N/A')}
        </div>
        <div style="display:flex;gap:10px;justify-content:center;margin-top:12px;">
          <button id="copyRefBtn" style="padding:10px 14px;border-radius:10px;border:1px solid #e6efe9;background:#fff;cursor:pointer;font-weight:700;">Copy</button>
          <button id="closeRefBtn" style="padding:10px 14px;border-radius:10px;border:none;background:linear-gradient(135deg,#10B981,#059669);color:#fff;cursor:pointer;font-weight:700;">Close</button>
        </div>
      </div>
    `;

  document.body.appendChild(modal);
  document.body.style.overflow = 'hidden';

  // Handlers
  const removeModal = () => {
    modal.remove();
    document.body.style.overflow = 'auto';
  };
  modal.querySelector('#orderRefClose').addEventListener('click', removeModal);
  modal.querySelector('#closeRefBtn').addEventListener('click', removeModal);

  modal.addEventListener('click', (e) => {
    if (e.target === modal) removeModal();
  });

  const copyBtn = modal.querySelector('#copyRefBtn');
  copyBtn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(String(ref || ''));
      copyBtn.textContent = 'Copied';
      copyBtn.style.background = '#10B981';
      copyBtn.style.color = '#fff';
      setTimeout(() => {
        copyBtn.textContent = 'Copy';
        copyBtn.style.background = '';
        copyBtn.style.color = '';
      }, 1600);
    } catch (err) {
      showNotification('Unable to copy reference to clipboard.', 'error');
    }
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

if (typeof window.PHP_IS_LOGGED_IN !== 'undefined') {
  isLoggedIn = !!window.PHP_IS_LOGGED_IN;
}

function initOrderStatusPolling() {
  return;
}

// Add keyframe animation for notifications
document.addEventListener('DOMContentLoaded', function () {
  const styleId = 'order-status-anim';
  if (!document.getElementById(styleId)) {
    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to   { transform: translateX(0);   opacity: 1; }
      }
    `;
    document.head.appendChild(style);
  }
  // initOrderStatusPolling(); // disabled to prevent duplicate notifications
});

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

document.addEventListener('DOMContentLoaded', () => {

  // make promo images clickable and show pointer
  document.querySelectorAll('.testimonial-img').forEach(img => {
    img.style.cursor = 'pointer';
    img.addEventListener('click', () => {
      if (typeof openTestimonialModal === 'function') {
        openTestimonialModal(img);
        return;
      }
      // fallback lightbox
      const overlay = document.createElement('div');
      overlay.id = 'promoLightbox';
      overlay.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.75);z-index:7000;';
      overlay.innerHTML = `
        <img src="${img.src}" style="max-width:90%;max-height:90%;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.6)">
        <button id="promoLightboxClose" style="position:absolute;top:18px;right:18px;background:#fff;border-radius:50%;width:40px;height:40px;border:none;font-size:20px;cursor:pointer;">×</button>
      `;
      document.body.appendChild(overlay);
      overlay.querySelector('#promoLightboxClose').addEventListener('click', () => overlay.remove());
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
    });
  });

  // faster carousel autoplay for .carousel-track
  const track = document.querySelector('.carousel-track');
  if (track) {
    let autoTimer = null;
    const firstItem = track.querySelector('.testimonial');
    const step = firstItem ? (firstItem.offsetWidth + (parseInt(getComputedStyle(firstItem).marginRight) || 12)) : Math.floor(track.clientWidth / 3);
    const intervalMs = 2500; // faster autoplay (2.5s)

    function startAuto() {
      stopAuto();
      autoTimer = setInterval(() => {
        // smooth scroll forward
        track.scrollBy({ left: step, behavior: 'smooth' });
        // if near end, go back to start after short delay
        if (track.scrollLeft + track.clientWidth >= track.scrollWidth - 8) {
          setTimeout(() => track.scrollTo({ left: 0, behavior: 'smooth' }), 600);
        }
      }, intervalMs);
    }
    function stopAuto() {
      if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    }

    // start autoplay, but pause on hover/focus
    startAuto();
    track.addEventListener('mouseenter', stopAuto);
    track.addEventListener('mouseleave', startAuto);
    track.addEventListener('focusin', stopAuto);
    track.addEventListener('focusout', startAuto);

    // optional: make track keyboard accessible items scroll when arrows pressed
    track.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { track.scrollBy({ left: step, behavior: 'smooth' }); }
      if (e.key === 'ArrowLeft') { track.scrollBy({ left: -step, behavior: 'smooth' }); }
    });
  }

});


function logout(event) {
  if (event) event.stopPropagation();
  fetch("logout.php", { method: "POST" })
    .then(() => {
      window.location.reload();
    });
}


async function handleRegister(event) {
  event.preventDefault();

  const firstName = document.getElementById("registerName").value.trim();
  const lastName = document.getElementById("registerLastName").value.trim();
  const email = document.getElementById("registerEmail").value.trim();
  const password = document.getElementById("registerPassword").value;
  const confirmPassword = document.getElementById("confirmPassword").value;
  const registerBtn = document.getElementById("registerBtn");

  // Basic validation
  if (!firstName) return showNotification("First name required", "error");
  if (!lastName) return showNotification("Last name required", "error");
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) return showNotification("Invalid email", "error");
  if (password.length < 8) return showNotification("Password must be at least 8 characters", "error");
  if (password !== confirmPassword) return showNotification("Passwords do not match", "error");

  registerBtn.disabled = true;
  registerBtn.classList.add("loading");

  try {
    const formData = new FormData();
    formData.append("registerName", firstName);
    formData.append("registerLastName", lastName);
    formData.append("registerEmail", email);
    formData.append("registerPassword", password);
    formData.append("confirmPassword", confirmPassword);

    const res = await fetch("register.php", {
      method: "POST",
      body: formData,
      credentials: "same-origin"
    });

    const data = await res.json();

    if (data.success) {
      showNotification("OTP sent to " + email, "success");
      showOtpModal(email);
    } else {
      showNotification(data.message || "Registration failed", "error");
    }
  } catch (err) {
    showNotification("Network error during registration", "error");
  } finally {
    registerBtn.disabled = false;
    registerBtn.classList.remove("loading");
  }
}


// Add this right after your other document.addEventListener blocks (around line 600)
// (before the window.addEventListener("scroll") blocks)

// Delegated handler for "View" buttons that use data-* attributes
document.addEventListener('click', function (e) {
  const btn = e.target.closest && e.target.closest('.view-btn');
  if (!btn) return;
  try {
    const id = btn.dataset.id;
    const name = btn.dataset.name;
    const price = Number(btn.dataset.price) || 0;
    const description = btn.dataset.desc || '';
    const image = btn.dataset.image || '';
    const dataType = btn.dataset.type || 'cold';
    let variants = null;
    if (btn.dataset.variants && btn.dataset.variants !== 'null') {
      try {
        variants = JSON.parse(btn.dataset.variants);
      } catch (err) {
        console.error('Failed to parse variants JSON:', err);
        variants = null;
      }
    }
    // Call product view handler
    handleViewProduct(id, name, price, description, image, dataType, variants);
  } catch (err) {
    console.error('Error handling view button click:', err);
  }
});


document.addEventListener('click', function (e) {
  const nav = e.target.closest && e.target.closest('.nav-item');
  if (!nav) return;
  // prevent default anchor behavior
  if (e.preventDefault) e.preventDefault();

  // prefer data-section attribute if present, otherwise use text
  const section = (nav.dataset && nav.dataset.section) ? nav.dataset.section.trim() : nav.textContent.trim().toLowerCase();
  // normalize common labels
  const map = {
    home: 'home',
    about: 'about',
    shop: 'products',
    menu: 'products',
    products: 'products',
    locations: 'locations',
    promos: 'promos',
    contact: 'contact'
  };
  const target = map[section] || section;
  if (typeof showSection === 'function') {
    showSection(target);
  } else {
    // fallback: try to find element by id
    const el = document.getElementById(target);
    if (el) {
      document.querySelectorAll('.section-content').forEach(s => { s.style.display = 'none'; s.classList.remove('active'); });
      el.style.display = 'block';
      el.classList.add('active');
      window.scrollTo(0, 0);
    }
  }
});


// Safe modal open helper — sets aria-hidden correctly and focuses dialog
function openPaymentModal(pickupData = {}) {
  const paymentModal = document.getElementById("paymentMethodModal");
  if (!paymentModal) return;

  // store the focused element so we can restore focus after closing
  __lastFocusedBeforePaymentModal = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  // ensure no element inside modal is focused while aria-hidden is true
  try { if (document.activeElement && paymentModal.contains(document.activeElement)) document.activeElement.blur(); } catch (e) { /* ignore */ }

  // store pickup data on modal dataset
  paymentModal.dataset.pickupName = pickupData.pickup_name || '';
  paymentModal.dataset.pickupLocation = pickupData.pickup_location || '';
  paymentModal.dataset.pickupTime = pickupData.pickup_time || '';
  paymentModal.dataset.specialInstructions = pickupData.special_instructions || '';

  // make modal visible and accessible
  paymentModal.classList.add("open");
  paymentModal.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";

  // hide any inline payment UI and GCASH preview
  const inline = document.getElementById("paymentChoicesInline");
  if (inline) inline.style.display = "none";
  const gcashPreview = document.getElementById("gcashPreview");
  if (gcashPreview) gcashPreview.style.display = "none";

  // focus the dialog panel for screen readers / keyboard users
  const dialog = paymentModal.querySelector('.payment-modal-dialog');
  if (dialog) {
    // ensure it is focusable
    dialog.setAttribute('tabindex', '-1');
    dialog.focus({ preventScroll: true });
  } else {
    // fallback focus first actionable button
    const firstBtn = paymentModal.querySelector('button, a, [tabindex]');
    if (firstBtn) firstBtn.focus();
  }
}

function showNotification(message, type = "success") {
  const notification = document.createElement("div");
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: ${type === "success" ? "linear-gradient(135deg, #10B981, #059669)" : "linear-gradient(135deg, #EF4444, #DC2626)"};
    color: white;
    padding: 16px 24px;
    border-radius: 12px;
    font-weight: 600;
    z-index: 9999;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    animation: slideIn .3s ease;
  `;
  notification.innerHTML = `<i class="fas fa-check-circle" style="margin-right:8px;"></i>${message}`;
  document.body.appendChild(notification);
  setTimeout(() => notification.remove(), 3000);
}

const CATEGORY = {
  premium: '5',
  specialty: '6',
  milk: '4',
  chocolate: '2',
  matcha: '3',
  alltime: '1',
  pastries: '7'
};

function filterDrinks(type) {
  lastDrinkType = type;
  const hotBtn = document.getElementById('hotDrinksBtn');
  const coldBtn = document.getElementById('coldDrinksBtn');
  const pasBtn = document.getElementById('pastriesBtn');
  hotBtn?.classList.toggle('active', type === 'hot');
  coldBtn?.classList.toggle('active', type === 'cold');
  pasBtn?.classList.toggle('active', type === 'pastries');

  // Allowed categories and type filter
  let allowedCats = [];
  let typeFilter = null; // 'hot' | 'cold' | null (ignore)
  if (type === 'hot') {
    allowedCats = [CATEGORY.premium];
    typeFilter = 'hot';
  } else if (type === 'pastries') {
    allowedCats = [CATEGORY.pastries];
    typeFilter = null; // ignore data-type for pastries
  } else {
    allowedCats = [CATEGORY.premium, CATEGORY.specialty, CATEGORY.milk, CATEGORY.chocolate, CATEGORY.matcha, CATEGORY.alltime];
    typeFilter = 'cold';
  }

  // Show/hide items
  const items = document.querySelectorAll('#products .product-item');
  items.forEach(item => {
    const cat = item.getAttribute('data-category');
    const dt = (item.getAttribute('data-type') || 'cold').toLowerCase();
    const catOk = allowedCats.includes(cat);
    const typeOk = typeFilter ? (dt === typeFilter) : true;
    const show = catOk && typeOk;
    item.style.display = show ? '' : 'none';
    item.style.opacity = show ? '1' : '0';
  });

  // REPLACE old header/list hiding with:
  updateMenuSectionVisibility();

  if (typeof loadTopProducts === 'function') loadTopProducts(type);
}
window.filterDrinks = filterDrinks;

// Add this helper once (below filterDrinks)
function updateMenuSectionVisibility() {
  document.querySelectorAll('#products .products-header').forEach(header => {
    const wrapper = header.nextElementSibling; // e.g., .pastries-section or .product-list
    const list = wrapper?.matches('.product-list')
      ? wrapper
      : wrapper?.querySelector('.product-list');
    if (!list) return;

    const anyVisible = Array.from(list.querySelectorAll('.product-item'))
      .some(it => it.style.display !== 'none');

    header.style.display = anyVisible ? '' : 'none';
    list.style.display = anyVisible ? '' : 'none';
    if (wrapper && wrapper !== list) wrapper.style.display = anyVisible ? '' : 'none';
  });
}


document.querySelectorAll('#products .products-header').forEach(header => {
  const wrapper = header.nextElementSibling;
  const list = wrapper?.matches('.product-list') ? wrapper : wrapper?.querySelector('.product-list');
  wrapper?.querySelector('.product-list');

  if (!list) return;

  const anyVisible = Array.from(list.querySelectorAll('.product-item'))
    .some(it => it.style.display !== 'none');

  header.style.display = anyVisible ? '' : 'none';
  list.style.display = anyVisible ? '' : 'none';
  if (wrapper && wrapper !== list) wrapper.style.display = anyVisible ? '' : 'none';
});

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
      otpState.cooldownUntil = data.cooldown ? Math.floor(Date.now() / 1000) + Number(data.cooldown) : 0;
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

  if (!code || code.length !== 6) {   // ✅ must be 6 digits
    errorEl.textContent = "Enter a valid 6-digit code.";
    return;
  }

  const btn = document.getElementById('verifyOtpBtn');
  btn.disabled = true;
  btn.textContent = 'Verifying...';

  try {
    const res = await fetch('/AJAX/verify_otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ otp: code })
    });

    const data = await res.json().catch(() => ({}));

    if (data.success) {
      // ✅ OTP verified, account created, session set
      showNotification("Welcome " + (data.user?.user_name || "") + "! Redirecting...", "success");

      // Optional modal message
      const modalMsg = document.getElementById('otpModalMsg');
      if (modalMsg) {
        modalMsg.innerHTML = '<div style="color:#10B981;font-weight:bold;margin:10px 0;"><i class="fas fa-check-circle"></i> Verification successful!</div>';
      }

      setTimeout(() => {
        closeOtpModal();
        closeAuthModal();
        // update in-memory state if needed
        window.isLoggedIn = true;
        window.currentUser = data.user || null;

        // redirect if backend provides, else fallback
        if (data.redirect) {
          window.location.href = data.redirect;
        } else {
          window.location.reload();
        }
      }, 1200);

    } else {
      errorEl.textContent = data.message || "Incorrect code.";
      if (typeof data.locked_for === 'number' && data.locked_for > 0) {
        showNotification(`Too many attempts. Try again in ${Math.ceil(data.locked_for / 60)} min.`, "error");
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

let previousOrderStatuses = {};
let initialLoadComplete = false;
const shownOrderNotifs = new Set(); // prevents duplicate ref+status notifications
// ...existing code...

function checkOrderStatusUpdates() {
  if (!isLoggedIn) return;

  const timestamp = Date.now();
  fetch(`AJAX/check_order_status.php?_=${timestamp}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache, no-store', 'Pragma': 'no-cache' }
  })
    .then(r => r.json())
    .then(data => {
      // 1) Show server-pushed unread updates (marked notified=0 on backend)
      if (Array.isArray(data.status_updates)) {
        data.status_updates.forEach(u => {
          const key = `${u.reference_number}:${(u.status || '').toLowerCase()}`;
          if (shownOrderNotifs.has(key)) return;
          shownOrderNotifs.add(key);
          showOrderStatusNotification(u);
        });
      }

      // 2) Also detect status changes locally to catch any missed updates
      if (Array.isArray(data.all_recent_orders)) {
        data.all_recent_orders.forEach(order => {
          const ref = order.reference_number;
          const cur = (order.status || '').toLowerCase();

          if (initialLoadComplete &&
            previousOrderStatuses[ref] &&
            previousOrderStatuses[ref].toLowerCase() !== cur) {
            const key = `${ref}:${cur}`;
            if (!shownOrderNotifs.has(key)) {
              shownOrderNotifs.add(key);
              showOrderStatusNotification({ reference_number: ref, status: cur });
            }
          }
          previousOrderStatuses[ref] = cur;
        });
        if (!initialLoadComplete) initialLoadComplete = true;
      }
    })
    .catch(err => console.error('Order status poll error:', err));
}

function showOrderStatusNotification(update) {
  const statusConfig = {
    pending: { color: '#f59e0b', icon: 'fa-clock', text: 'Pending' },
    confirmed: { color: '#3b82f6', icon: 'fa-check-circle', text: 'Confirmed' },
    preparing: { color: '#8b5cf6', icon: 'fa-mug-hot', text: 'Preparing' },
    ready: { color: '#10b981', icon: 'fa-check-double', text: 'Ready for Pickup' },
    completed: { color: '#059669', icon: 'fa-check-square', text: 'Completed' },
    cancelled: { color: '#ef4444', icon: 'fa-times-circle', text: 'Cancelled' }
  };

  const status = (update.status || '').toLowerCase();
  const config = statusConfig[status] || { color: '#6b7280', icon: 'fa-info-circle', text: status.charAt(0).toUpperCase() + status.slice(1) };

  // Center-top container
  let container = document.getElementById('orderStatusNotifications');
  if (!container) {
    container = document.createElement('div');
    container.id = 'orderStatusNotifications';
    container.style.position = 'fixed';
    container.style.top = '16px';
    container.style.left = '50%';
    container.style.transform = 'translateX(-50%)';
    container.style.zIndex = '9999';
    container.style.display = 'flex';
    container.style.flexDirection = 'column';
    container.style.alignItems = 'center';
    container.style.gap = '10px';
    container.style.pointerEvents = 'none'; // let clicks pass through except on notifications
    document.body.appendChild(container);
  }

  // Notification card
  const notification = document.createElement('div');
  notification.className = 'order-status-notification';
  notification.style.pointerEvents = 'auto';
  notification.style.backgroundColor = 'white';
  notification.style.borderLeft = `4px solid ${config.color}`;
  notification.style.borderRadius = '12px';
  notification.style.boxShadow = '0 8px 24px rgba(0,0,0,0.12)';
  notification.style.margin = '0';
  notification.style.padding = '14px 16px';
  notification.style.width = '420px';
  notification.style.maxWidth = '90vw';
  notification.style.animation = 'slideIn 0.3s ease';

  notification.innerHTML = `
    <div style="display:flex;align-items:center;">
      <div style="background:${config.color};color:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;margin-right:12px;">
        <i class="fas ${config.icon}"></i>
      </div>
      <div style="flex:1;">
        <div style="font-weight:600;margin-bottom:4px;">Order ${update.reference_number || ''}</div>
        <div style="font-size:0.9em;color:#374151;">Status: <span style="color:${config.color};font-weight:500;">${config.text}</span></div>
      </div>
    </div>
  `;

  // Close button
  const closeBtn = document.createElement('button');
  closeBtn.innerHTML = '&times;';
  closeBtn.style.position = 'absolute';
  closeBtn.style.top = '8px';
  closeBtn.style.right = '8px';
  closeBtn.style.background = 'transparent';
  closeBtn.style.border = 'none';
  closeBtn.style.fontSize = '16px';
  closeBtn.style.cursor = 'pointer';
  closeBtn.style.color = '#6b7280';
  closeBtn.onclick = () => notification.remove();
  notification.appendChild(closeBtn);
  notification.style.position = 'relative';

  container.appendChild(notification);

  setTimeout(() => {
    notification.style.opacity = '0';
    notification.style.transform = 'translateY(-8px)';
    notification.style.transition = 'opacity .3s, transform .3s';
    setTimeout(() => notification.remove(), 300);
  }, 8000);
}

function getStatusMessage(status, location, time) {
  switch (status.toLowerCase()) {
    case 'confirmed':
      return 'Your order has been confirmed and will be prepared soon.';
    case 'preparing':
      return 'We are currently preparing your order.';
    case 'ready':
      return 'Your order is ready for pickup at ' +
        (location || 'our store') +
        (time ? ' at ' + time : '') + '.';
    case 'completed':
      return 'Thank you for your order! We hope you enjoyed your drinks.';
    case 'cancelled':
      return 'Your order has been cancelled. Please contact us for assistance.';
    default:
      return 'Your order status has been updated to: ' + status;
  }
}

// Add animation styles to document
document.addEventListener('DOMContentLoaded', function () {
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
                        <div class=\"badge bg-warning text-dark mb-2\" style=\"font-size:0.95em;\">#${idx + 1} Best Seller</div>
                    </div>`;
        });
        html += '</div></div>';
      } else {
        html = `<div class=\"products-header\"><h3 style=\"font-size:2.2rem;font-weight:800;color:#b45309;margin-bottom:0.5em;\">Top Products (${category.charAt(0).toUpperCase() + category.slice(1)} Drinks)</h3><div class=\"text-muted\" style=\"font-size:1.1em;\">No products to show yet.</div></div>`;
      }
      container.innerHTML = html;
    });
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


document.addEventListener('click', function (event) {
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

  if (typeof filterDrinks === 'function') {
    filterDrinks('cold');
  }
});

document.addEventListener("DOMContentLoaded", function () {
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


async function loadActiveToppings() {
  try {
    const res = await fetch('admin/AJAX/get_toppings.php?action=active', { cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    const container = document.getElementById('toppingsList');
    if (!container) return;
    if (!data.success || !Array.isArray(data.toppings) || !data.toppings.length) {
      container.innerHTML = '<div style="padding:8px 6px;font-size:.9rem;color:#9ca3af;">No toppings available.</div>';
      return;
    }
    container.innerHTML = data.toppings.map(t => {
      const safeName = (t.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const key = (t.id || safeName.toLowerCase().replace(/\s+/g, '-'));
      const price = Number(t.price || 0).toFixed(2);
      return `<button type="button" class="add-on-btn" data-key="${key}" data-price="${price}">
                <span>${safeName}</span>
                <span class="price">₱${price}</span>
              </button>`;
    }).join('');
    modalSelectedToppings = {};
    if (typeof recalcModalTotal === 'function') recalcModalTotal();
  } catch (e) {
    const container = document.getElementById('toppingsList');
    if (container) container.innerHTML = '<div style="padding:8px 6px;font-size:.9rem;color:#dc2626;">Failed to load toppings.</div>';
    console.error('loadActiveToppings error', e);
  }
}

// ensure toppings are loaded on page ready
document.addEventListener('DOMContentLoaded', () => {
  loadActiveToppings();
});

// also reload toppings whenever a product modal is opened.
// Matches common openers; add any selector your theme uses (e.g. .btn-view, .view-product, [data-open-product])
document.addEventListener('click', (e) => {
  const sel = e.target;
  if (sel.closest('.btn-view') || sel.closest('.view-product') || sel.closest('[data-open-product]') || sel.classList.contains('product-view-btn')) {
    // tiny delay if modal content is created dynamically
    setTimeout(loadActiveToppings, 40);
  }
});




async function submitAddToppingPublic(formData) {
  try {
    // POST to admin AJAX endpoint; endpoint expects form-data with action=add
    const body = new URLSearchParams();
    body.append('action', 'add');
    body.append('name', formData.get('name'));
    body.append('price', formData.get('price'));
    // Default status = active
    body.append('status', 'active');

    const res = await fetch('admin/AJAX/get_toppings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    });
    const data = await res.json();
    return data;
  } catch (err) {
    console.error('submitAddToppingPublic error', err);
    return { success: false, message: 'Network error' };
  }
}

// wire up public add-topping modal (admin-only button)
document.addEventListener('DOMContentLoaded', () => {
  const showBtn = document.getElementById('showAddToppingModalBtn');
  const modal = document.getElementById('addToppingModalPublic');
  const closeBtn = document.getElementById('closeAddToppingModalPublic');
  const cancelBtn = document.getElementById('cancelAddToppingPublic');
  const form = document.getElementById('addToppingFormPublic');
  const resultEl = document.getElementById('addToppingPublicResult');

  if (showBtn && modal) {
    showBtn.addEventListener('click', () => {
      resultEl.textContent = '';
      form.reset();
      modal.style.display = 'flex';
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', () => { if (modal) modal.style.display = 'none'; });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { if (modal) modal.style.display = 'none'; });

  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      resultEl.textContent = '';
      const fd = new FormData(form);
      // Basic client-side validation
      const name = (fd.get('name') || '').toString().trim();
      const price = (fd.get('price') || '0').toString().trim();
      if (!name) { resultEl.textContent = 'Name required'; return; }
      const data = await submitAddToppingPublic(fd);
      if (data && data.success) {
        // close modal and refresh toppings checkboxes shown in modal
        if (modal) modal.style.display = 'none';
        // reload active toppings into modal (public function)
        if (typeof loadActiveToppings === 'function') {
          // small delay to allow backend insert to commit
          setTimeout(loadActiveToppings, 120);
        }
      } else {
        resultEl.textContent = data.message || 'Failed to add topping';
      }
    });
  }
});

function handleViewProduct(id, name, price, description, image, dataType, variants) {
  try {
    console.log("handleViewProduct called", { id, name, price, dataType, variants });
    // Require login
    if (!isLoggedIn) { showLoginModal(); return; }

    dataType = (dataType || 'cold').toString().toLowerCase();


    // Get UI elements
    const sizeTitleEl = document.querySelector(".product-modal-sizes h3");
    const sizeButtons = document.querySelector(".size-buttons");
    const nameEl = document.getElementById("modalProductName");
    const descEl = document.getElementById("modalProductDescription");
    const imgEl = document.getElementById("modalProductImage");
    const priceEl = document.getElementById("modalProductPrice");

    // Set default prices based on type
    let grandePrice = 140, supremePrice = 170;
    if (dataType === 'hot') { grandePrice = 120; supremePrice = 150; }

    // Build product object
    currentProduct = {
      id,
      name,
      description,
      image,
      dataType,
      price: (typeof price === 'number' && price > 0) ? price : (dataType === 'pastries' ? 60 : grandePrice)
    };

    // Pastries: show variants/options
    if (dataType === 'pastries') {
      // Default variants if none provided
      let v = [];

      // Custom pricing for specific pastries
      const nameLc = name.toLowerCase();

      if (nameLc.includes('crème flan') || nameLc.includes('creme flan') || nameLc.includes('flan')) {
        v = [
          { label: 'Per piece', price: 60 },
          { label: 'Box of 4', price: 230 },
          { label: 'Box of 6', price: 350 }
        ];
      } else if (nameLc.includes('egg pie')) {
        v = [
          { label: 'Per slice', price: 60 },
          { label: 'Whole', price: 380 }
        ];
      } else if (Array.isArray(variants) && variants.length) {
        v = variants;
      } else {
        v = [{ label: 'Standard', price: currentProduct.price }];
      }

      currentProduct.variants = v.slice();
      selectedSize = v[0].label;
      currentProduct.price = v[0].price;

      // Update UI
      if (sizeTitleEl) sizeTitleEl.textContent = 'Options';
      if (sizeButtons) {
        sizeButtons.innerHTML = v.map((opt, i) =>
          `<button class="size-btn ${i === 0 ? 'active' : ''}" data-label="${opt.label}" 
           data-price="${opt.price}">${opt.label}</button>`
        ).join('');
        sizeButtons.querySelectorAll('.size-btn').forEach(btn => {
          btn.onclick = (e) => {
            e.stopPropagation();
            selectSize(btn.dataset.label);
          };
        });
      }
    } else {
      // Drinks: restore default two buttons
      currentProduct.grandePrice = grandePrice;
      currentProduct.supremePrice = supremePrice;
      selectedSize = "Grande";
      currentProduct.price = grandePrice;

      if (sizeTitleEl) sizeTitleEl.textContent = 'Size';
      if (sizeButtons) {
        sizeButtons.innerHTML = `
          <button class="size-btn active">Grande</button>
          <button class="size-btn">Supreme</button>
        `;
        sizeButtons.querySelectorAll('.size-btn').forEach(btn => {
          const text = btn.textContent.trim();
          btn.onclick = (e) => { e.stopPropagation(); selectSize(text); };
        });
      }
    }

    // Populate modal UI
    if (nameEl) nameEl.textContent = name || '';
    if (descEl) descEl.textContent = description || '';
    if (imgEl) { imgEl.src = image || ''; imgEl.alt = name || ''; }

    modalSelectedToppings = {};
    document.querySelectorAll('.add-on-btn').forEach(btn => {
      btn.classList.remove('active');
    });

    const toppingsContainer = document.getElementById('toppingsList');
    if (toppingsContainer) {
      // keep the markup defined in index.php, ensure all checkboxes are unchecked
      toppingsContainer.querySelectorAll('.topping-checkbox').forEach(cb => cb.checked = false);
    }
    // compute initial displayed price (includes selected size but not toppings)
    recalcModalTotal();
    // Cleanly bind Add to Cart
    const detailsSection = document.querySelector(".product-modal-details");
    const addBtn = detailsSection ? detailsSection.querySelector(".product-modal-add-cart") : null;
    if (addBtn) {
      const newBtn = addBtn.cloneNode(true);
      addBtn.parentNode.replaceChild(newBtn, addBtn);
      newBtn.onclick = (e) => { e.stopPropagation(); addProductToCart(); closeProductModal(); };
    }

    // Open modal
    const modal = document.getElementById("productModal");
    if (modal) {
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
      const yellowCloseBtn = modal.querySelector('.product-modal-close-yellow');
      if (yellowCloseBtn) yellowCloseBtn.onclick = (ev) => { ev.stopPropagation(); closeProductModal(); };
    }

    document.querySelectorAll('.topping-checkbox').forEach(cb => {
      cb.onchange = function (e) {
        const key = cb.getAttribute('data-key');
        const price = parseFloat(cb.getAttribute('data-price')) || 0;
        if (cb.checked) {
          modalSelectedToppings[key] = { price, qty: 1, name: cb.parentNode.textContent.trim() };
        } else {
          delete modalSelectedToppings[key];
        }
        recalcModalTotal();
      };
    });
  } catch (err) {
    console.error('handleViewProduct error', err);
  }


} function selectSize(size) {
  selectedSize = size;

  // update UI highlight (match by text, data-size or data-label)
  document.querySelectorAll(".size-btn").forEach((btn) => {
    const txt = btn.textContent.trim();
    const ds = btn.dataset.size || btn.dataset.label || '';
    btn.classList.toggle("active", txt === size || ds === size);
  });

  // Update price text based on size
  if (currentProduct) {
    if (currentProduct.dataType === 'pastries' && Array.isArray(currentProduct.variants)) {
      const chosen = currentProduct.variants.find(v => v.label === size) || currentProduct.variants[0];
      currentProduct.price = chosen.price;
      const priceEl = document.getElementById("modalProductPrice");
      if (priceEl) priceEl.textContent = `Php ${chosen.price} (${chosen.label})`;
    } else if (size === "Grande") {
      currentProduct.price = currentProduct.grandePrice ?? currentProduct.price;
      const priceEl = document.getElementById("modalProductPrice");
      if (priceEl) priceEl.textContent = `Php ${currentProduct.grandePrice ?? currentProduct.price} (Grande)`;
    } else {
      currentProduct.price = currentProduct.supremePrice ?? currentProduct.price;
      const priceEl = document.getElementById("modalProductPrice");
      if (priceEl) priceEl.textContent = `Php ${currentProduct.supremePrice ?? currentProduct.price} (Supreme)`;
    }
  }

  // recalc total so modalTotal updates immediately when size changes
  if (typeof recalcModalTotal === 'function') recalcModalTotal();
} function handleCheckout() {
  const deliveryOptions = document.getElementById("deliveryOptions");

  // If delivery/pickup form is not visible yet — show it first
  const deliveryVisible = deliveryOptions && window.getComputedStyle(deliveryOptions).display !== "none";
  if (!deliveryVisible) {
    startCheckout();
    return;
  }

  // validate pickup fields
  const pickup_name = (document.getElementById("pickupName")?.value || "").trim();
  const pickup_location = (document.getElementById("pickupLocation")?.value || "").trim();
  const pickup_time = (document.getElementById("pickupTime")?.value || "").trim();
  if (!pickup_name || !pickup_location || !pickup_time) {
    showNotification("Please fill out all required pickup details.", "error");
    return;
  }

  // stash pickup details on cartTotal element for later use (keeps previous behaviour)
  const cartTotal = document.getElementById('cartTotal');
  if (cartTotal) {
    cartTotal.dataset.pickupName = pickup_name;
    cartTotal.dataset.pickupLocation = pickup_location;
    cartTotal.dataset.pickupTime = pickup_time;
    cartTotal.dataset.specialInstructions = document.getElementById("specialInstructions")?.value || '';
  }

  // Open the payment modal via the safe helper below
  openPaymentModal({
    pickup_name,
    pickup_location,
    pickup_time,
    special_instructions: document.getElementById("specialInstructions")?.value || ''
  });
}



function handlePaymentChoice(method) {
  // Ignore passed method; enforce cash
  const paymentModal = document.getElementById('paymentMethodModal');
  if (!paymentModal) return;
  closePaymentModal();

  const payload = {
    pickup_name: paymentModal.dataset.pickupName || '',
    pickup_location: paymentModal.dataset.pickupLocation || '',
    pickup_time: paymentModal.dataset.pickupTime || '',
    special_instructions: paymentModal.dataset.specialInstructions || '',
    payment_method: 'cash'
  };
  submitPickupForm(payload);
}


function handlePaymentChoice(method) {
  const paymentModal = document.getElementById("paymentMethodModal");
  if (!paymentModal) return;
  paymentModal.dataset.paymentMethod = method;

  if (method === "cash") {
    // close modal then submit
    closePaymentModal();
    const payload = {
      pickup_name: paymentModal.dataset.pickupName || '',
      pickup_location: paymentModal.dataset.pickupLocation || '',
      pickup_time: paymentModal.dataset.pickupTime || '',
      special_instructions: paymentModal.dataset.specialInstructions || '',
      payment_method: "cash"
    };
    submitPickupForm(payload);
    return;
  }

  if (method === "gcash") {
    // reveal gcash preview inside dialog
    const gcashPreview = document.getElementById("gcashPreview");
    if (gcashPreview) gcashPreview.style.display = "block";
  }
}


function submitGcashCheckout() {
  const paymentModal = document.getElementById("paymentMethodModal");
  if (!paymentModal) return;
  const payload = {
    pickup_name: paymentModal.dataset.pickupName || '',
    pickup_location: paymentModal.dataset.pickupLocation || '',
    pickup_time: paymentModal.dataset.pickupTime || '',
    special_instructions: paymentModal.dataset.specialInstructions || '',
    payment_method: "gcash"
  };
  closePaymentModal();
  submitPickupForm(payload);
}

// wire modal close actions on DOMContentLoaded
document.addEventListener("DOMContentLoaded", function () {
  const paymentModal = document.getElementById("paymentMethodModal");
  if (paymentModal) {
    // close when clicking backdrop
    paymentModal.querySelectorAll('[data-close="backdrop"]').forEach(el => {
      el.addEventListener('click', closePaymentModal);
    });
    // close button
    const closeBtn = paymentModal.querySelector(".payment-modal-close");
    if (closeBtn) closeBtn.addEventListener('click', closePaymentModal);
  }

  // wire GCash Done inside dialog (if exists)
  const gcashDone = document.getElementById("gcashDoneBtn");
  if (gcashDone) {
    gcashDone.removeEventListener("click", submitGcashCheckout);
    gcashDone.addEventListener("click", function (e) {
      e.preventDefault && e.preventDefault();
      submitGcashCheckout();
    });
  }
});

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
  document.getElementById('registerForm').addEventListener('submit', function (e) {
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
window.addEventListener("DOMContentLoaded", function () {
  var showTermsBtn = document.getElementById('showTermsBtn');
  var termsModal = document.getElementById('termsModal');
  if (showTermsBtn && termsModal) {
    showTermsBtn.onclick = function (e) {
      e.preventDefault();
      termsModal.classList.add('active');
    };
  }
});

// AJAX check for fullname/email 
window.addEventListener("DOMContentLoaded", function () {
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
    registerEmail.addEventListener("blur", function () {
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


// ...existing code...
(function () {
  // canonical implementations (final authority) — prevents accidental overwrites
  function _canonicalHandlePaymentChoice(method) {
    const paymentModal = document.getElementById("paymentMethodModal");
    if (!paymentModal) return;
    paymentModal.dataset.paymentMethod = method;

    if (method === "cash") {
      // close UI and submit as cash
      try { closePaymentModal(); } catch (e) { /*ignore*/ }
      const payload = {
        pickup_name: paymentModal.dataset.pickupName || '',
        pickup_location: paymentModal.dataset.pickupLocation || '',
        pickup_time: paymentModal.dataset.pickupTime || '',
        special_instructions: paymentModal.dataset.specialInstructions || '',
        payment_method: "cash"
      };
      submitPickupForm(payload);
      return;
    }

    if (method === "gcash") {
      const gcashPreview = document.getElementById("gcashPreview");
      if (gcashPreview) gcashPreview.style.display = "block";
      // focus done button if available
      const done = document.getElementById("gcashDoneBtn");
      if (done && typeof done.focus === "function") done.focus();
    }
  }

  function _canonicalSelectSize(size) {
    selectedSize = size;

    // update UI highlight (match by text, data-size or data-label)
    document.querySelectorAll(".size-btn").forEach((btn) => {
      const txt = btn.textContent.trim();
      const ds = btn.dataset.size || btn.dataset.label || "";
      btn.classList.toggle("active", txt === size || ds === size);
    });

    // Update price text based on size and recalc totals
    if (currentProduct) {
      if (currentProduct.dataType === "pastries" && Array.isArray(currentProduct.variants)) {
        const chosen = currentProduct.variants.find((v) => v.label === size) || currentProduct.variants[0];
        currentProduct.price = chosen.price;
        const priceEl = document.getElementById("modalProductPrice");
        if (priceEl) priceEl.textContent = `Php ${chosen.price} (${chosen.label})`;
      } else if (size === "Grande") {
        currentProduct.price = currentProduct.grandePrice ?? currentProduct.price;
        const priceEl = document.getElementById("modalProductPrice");
        if (priceEl) priceEl.textContent = `Php ${currentProduct.grandePrice ?? currentProduct.price} (Grande)`;
      } else {
        currentProduct.price = currentProduct.supremePrice ?? currentProduct.price;
        const priceEl = document.getElementById("modalProductPrice");
        if (priceEl) priceEl.textContent = `Php ${currentProduct.supremePrice ?? currentProduct.price} (Supreme)`;
      }
    }

    if (typeof recalcModalTotal === "function") recalcModalTotal();
  }

  try {
    Object.defineProperty(window, "handlePaymentChoice", {
      value: _canonicalHandlePaymentChoice,
      writable: false,
      configurable: false
    });
  } catch (e) {
    // fallback: assign if defineProperty failed
    window.handlePaymentChoice = _canonicalHandlePaymentChoice;
  }

  try {
    Object.defineProperty(window, "selectSize", {
      value: _canonicalSelectSize,
      writable: false,
      configurable: false
    });
  } catch (e) {
    window.selectSize = _canonicalSelectSize;
  }
})();