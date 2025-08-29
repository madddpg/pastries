window.toggleProfileDropdown = (event) => {
  event.stopPropagation()
  var menu = document.getElementById("profileDropdownMenu")
  if (menu) {
    menu.classList.toggle("show")
  }
}

const cart = []
let currentSection = "home"
let isLoggedIn = false
let currentUser = null
const deliveryMethod = "pickup"
let selectedSize = "Grande"
const currentProduct = null
let lastDrinkType = "cold"
const modalSelectedToppings = {}
let __lastFocusedBeforePaymentModal = null

const otpState = {
  email: null,
  expiresAt: 0,
  cooldownUntil: 0,
  countdownTimer: null,
  cooldownTimer: null,
}

const TOPPINGS = [
  { key: "extra_shot", name: "Extra shot (coffee)", price: 40 },
  { key: "oatmilk", name: "Oatmilk", price: 50 },
  { key: "extra_sauce", name: "Extra sauce (milk-based)", price: 20 },
  { key: "whipped_cream", name: "Additional whipped cream", price: 20 },
]

function recalcModalTotal() {
  if (!currentProduct) return

  // determine base price for the currently selected size/variant
  let base = Number(currentProduct.price || 0)
  if (currentProduct.dataType !== "pastries") {
    base =
      selectedSize === "Grande"
        ? Number(currentProduct.grandePrice ?? base)
        : Number(currentProduct.supremePrice ?? base)
  } else if (currentProduct.dataType === "pastries" && currentProduct.variants) {
    base = Number(currentProduct.price ?? base)
  }

  // sum toppings
  let toppingsSum = 0
  Object.values(modalSelectedToppings || {}).forEach((t) => {
    const qty = Number(t.qty || t.quantity || 1)
    toppingsSum += Number(t.price || 0) * qty
  })

  const total = base + toppingsSum

  // update modal total (base + toppings)
  const totalEl = document.getElementById("modalTotalAmount")
  if (totalEl) totalEl.textContent = Number(total).toFixed(2)

  // update product base price display (do NOT overwrite with total)
  const priceEl = document.getElementById("modalProductPrice")
  if (priceEl) priceEl.textContent = `Php ${Number(base).toFixed(2)}`

  // update variant label if present
  const pv = document.getElementById("modalPriceVariant")
  if (pv) pv.textContent = `(${selectedSize})`
}

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
      filterDrinks(lastDrinkType)
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

function updateMenuSectionVisibility() {
  document.querySelectorAll("#products .products-header").forEach((header) => {
    const wrapper = header.nextElementSibling // e.g., .pastries-section or .product-list
    const list = wrapper?.matches(".product-list") ? wrapper : wrapper?.querySelector(".product-list")
    if (!list) return

    const anyVisible = Array.from(list.querySelectorAll(".product-item")).some((it) => it.style.display !== "none")

    header.style.display = anyVisible ? "" : "none"
    list.style.display = anyVisible ? "" : "none"
    if (wrapper && wrapper !== list) wrapper.style.display = anyVisible ? "" : "none"
  })
}

function getCsrfToken() {
  // Prefer meta tag if present, else a global set by PHP
  const meta = document.querySelector('meta[name="csrf_token"]')
  if (meta && meta.content) return meta.content
  if (typeof window.PHP_CSRF_TOKEN !== "undefined") return window.PHP_CSRF_TOKEN
  return "" // backend should ignore if not required
}

function handleCheckout() {
  const deliveryOptions = document.getElementById("deliveryOptions")

  // If delivery/pickup form is not visible yet — show it first
  const deliveryVisible = deliveryOptions && window.getComputedStyle(deliveryOptions).display !== "none"
  if (!deliveryVisible) {
    startCheckout()
    return
  }

  // validate pickup fields
  const pickup_name = (document.getElementById("pickupName")?.value || "").trim()
  const pickup_location = (document.getElementById("pickupLocation")?.value || "").trim()
  const pickup_time = (document.getElementById("pickupTime")?.value || "").trim()
  const special_instructions = (document.getElementById("specialInstructions")?.value || "").trim()

  if (!pickup_name || !pickup_location || !pickup_time) {
    showNotification("Please fill out all required pickup details.", "error")
    return
  }

  // stash pickup details on cartTotal element for later use (keeps previous behaviour)
  const cartTotal = document.getElementById("cartTotal")
  if (cartTotal) {
    cartTotal.dataset.pickupName = pickup_name
    cartTotal.dataset.pickupLocation = pickup_location
    cartTotal.dataset.pickupTime = pickup_time
    cartTotal.dataset.specialInstructions = special_instructions
  }

  // Open the payment modal via the safe helper below
  openPaymentModal({
    pickup_name,
    pickup_location,
    pickup_time,
    special_instructions,
  })
}

// Safe modal open helper — sets aria-hidden correctly and focuses dialog
function openPaymentModal(pickupData = {}) {
  const paymentModal = document.getElementById("paymentMethodModal")
  if (!paymentModal) return

  // store the focused element so we can restore focus after closing
  __lastFocusedBeforePaymentModal = document.activeElement instanceof HTMLElement ? document.activeElement : null

  // ensure no element inside modal is focused while aria-hidden is true
  try {
    if (document.activeElement && paymentModal.contains(document.activeElement)) document.activeElement.blur()
  } catch (e) {
    /* ignore */
  }

  // store pickup data on modal dataset
  paymentModal.dataset.pickupName = pickupData.pickup_name || ""
  paymentModal.dataset.pickupLocation = pickupData.pickup_location || ""
  paymentModal.dataset.pickupTime = pickupData.pickup_time || ""
  paymentModal.dataset.specialInstructions = pickupData.special_instructions || ""

  // make modal visible and accessible
  paymentModal.classList.add("open")
  paymentModal.setAttribute("aria-hidden", "false")
  document.body.style.overflow = "hidden"

  // hide any inline payment UI and GCASH preview
  const inline = document.getElementById("paymentChoicesInline")
  if (inline) inline.style.display = "none"
  const gcashPreview = document.getElementById("gcashPreview")
  if (gcashPreview) gcashPreview.style.display = "none"

  // focus the dialog panel for screen readers / keyboard users
  const dialog = paymentModal.querySelector(".payment-modal-dialog")
  if (dialog) {
    // ensure it is focusable
    dialog.setAttribute("tabindex", "-1")
    dialog.focus({ preventScroll: true })
  } else {
    // fallback focus first actionable button
    const firstBtn = paymentModal.querySelector("button, a, [tabindex]")
    if (firstBtn) firstBtn.focus()
  }
}

// close helper updated to restore focus
function closePaymentModal() {
  const paymentModal = document.getElementById("paymentMethodModal")
  if (!paymentModal) return
  paymentModal.classList.remove("open")
  paymentModal.setAttribute("aria-hidden", "true")
  document.body.style.overflow = "auto"

  // hide GCASH preview to reset state
  const gcashPreview = document.getElementById("gcashPreview")
  if (gcashPreview) gcashPreview.style.display = "none"

  // restore focus to previously focused element if safe
  try {
    if (__lastFocusedBeforePaymentModal && typeof __lastFocusedBeforePaymentModal.focus === "function") {
      __lastFocusedBeforePaymentModal.focus()
    } else {
      // fallback: focus checkout button in cart
      const checkout = document.querySelector(".checkout-btn")
      if (checkout) checkout.focus()
    }
  } catch (e) {
    /* ignore focus restore errors */
  }
}

function handlePaymentChoice(method) {
  const paymentModal = document.getElementById("paymentMethodModal")
  if (!paymentModal) return
  paymentModal.dataset.paymentMethod = method

  if (method === "cash") {
    // close modal then submit
    closePaymentModal()
    const payload = {
      pickup_name: paymentModal.dataset.pickupName || "",
      pickup_location: paymentModal.dataset.pickupLocation || "",
      pickup_time: paymentModal.dataset.pickupTime || "",
      special_instructions: paymentModal.dataset.specialInstructions || "",
      payment_method: "cash",
    }
    submitPickupForm(payload)
    return
  }

  if (method === "gcash") {
    // reveal gcash preview inside dialog
    const gcashPreview = document.getElementById("gcashPreview")
    if (gcashPreview) gcashPreview.style.display = "block"
    // move focus to done button in preview
    const done = document.getElementById("gcashDoneBtn")
    if (done) done.focus()
  }
}

function submitGcashCheckout() {
  const paymentModal = document.getElementById("paymentMethodModal")
  if (!paymentModal) return
  const payload = {
    pickup_name: paymentModal.dataset.pickupName || "",
    pickup_location: paymentModal.dataset.pickupLocation || "",
    pickup_time: paymentModal.dataset.pickupTime || "",
    special_instructions: paymentModal.dataset.specialInstructions || "",
    payment_method: "gcash",
  }
  closePaymentModal()
  submitPickupForm(payload)
}

function selectSize(size) {
  selectedSize = size

  // update UI highlight (match by text, data-size or data-label)
  document.querySelectorAll(".size-btn").forEach((btn) => {
    const txt = btn.textContent.trim()
    const ds = btn.dataset.size || btn.dataset.label || ""
    btn.classList.toggle("active", txt === size || ds === size)
  })

  // Update price text based on size
  if (currentProduct) {
    if (currentProduct.dataType === "pastries" && Array.isArray(currentProduct.variants)) {
      const chosen = currentProduct.variants.find((v) => v.label === size) || currentProduct.variants[0]
      currentProduct.price = chosen.price
      const priceEl = document.getElementById("modalProductPrice")
      if (priceEl) priceEl.textContent = `Php ${chosen.price} (${chosen.label})`
    } else if (size === "Grande") {
      currentProduct.price = currentProduct.grandePrice ?? currentProduct.price
      const priceEl = document.getElementById("modalProductPrice")
      if (priceEl) priceEl.textContent = `Php ${currentProduct.grandePrice ?? currentProduct.price} (Grande)`
    } else {
      currentProduct.price = currentProduct.supremePrice ?? currentProduct.price
      const priceEl = document.getElementById("modalProductPrice")
      if (priceEl) priceEl.textContent = `Php ${currentProduct.supremePrice ?? currentProduct.price} (Supreme)`
    }
  }

  // recalc total so modalTotal updates immediately when size changes
  if (typeof recalcModalTotal === "function") recalcModalTotal()
}

document.addEventListener("DOMContentLoaded", () => {
  // make promo images clickable and show pointer
  document.querySelectorAll(".testimonial-img").forEach((img) => {
    img.style.cursor = "pointer"
    img.addEventListener("click", () => {
      if (typeof window.openTestimonialModal === "function") {
        window.openTestimonialModal(img)
        return
      }
      // fallback lightbox
      const overlay = document.createElement("div")
      overlay.id = "promoLightbox"
      overlay.style.cssText =
        "position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.75);z-index:7000;"
      overlay.innerHTML = `
        <img src="${img.src}" style="max-width:90%;max-height:90%;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.6)">
        <button id="promoLightboxClose" style="position:absolute;top:18px;right:18px;background:#fff;border-radius:50%;width:40px;height:40px;border:none;font-size:20px;cursor:pointer;">×</button>
      `
      document.body.appendChild(overlay)
      overlay.querySelector("#promoLightboxClose").addEventListener("click", () => overlay.remove())
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) overlay.remove()
      })
    })
  })

  // Initialize user data if logged in
  if (window.PHP_IS_LOGGED_IN === true) {
    isLoggedIn = true
    currentUser = currentUser || {}
    if (window.PHP_USER_FN) {
      currentUser.firstName = window.PHP_USER_FN
    }
    if (window.PHP_USER_LN) {
      currentUser.lastName = window.PHP_USER_LN
    }
    if (window.PHP_USER_EMAIL) {
      currentUser.email = window.PHP_USER_EMAIL
    }
    if (window.PHP_USER_IMAGE) {
      currentUser.profileImage = window.PHP_USER_IMAGE
    }
    if (window.PHP_USER_ID) {
      currentUser.id = window.PHP_USER_ID
    }
  }

  // Initialize cart and show home section
  loadCart()
  updateCartCount()
  updateCartDisplay()
  showSection("home")
})

function filterDrinks(drinkType) {
  lastDrinkType = drinkType
  // Implementation for filtering drinks based on type
  console.log(`Filtering drinks by type: ${drinkType}`)
}

function startCheckout() {
  // Implementation for starting checkout process
  console.log("Starting checkout process")
}

function showNotification(message, type = "success") {
  // Implementation for showing notification
  const notification = document.createElement("div")
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
  `
  notification.textContent = message
  document.body.appendChild(notification)
  setTimeout(() => notification.remove(), 3000)
}

function submitPickupForm(payload) {
  // Implementation for submitting pickup form
  console.log("Submitting pickup form with payload:", payload)
}

function loadCart() {
  // Implementation for loading cart from storage
  try {
    const savedCart = localStorage.getItem("cart")
    if (savedCart) {
      cart.length = 0
      cart.push(...JSON.parse(savedCart))
    }
  } catch (e) {
    console.error("Error loading cart:", e)
  }
}

function updateCartCount() {
  // Implementation for updating cart count display
  const totalItems = cart.reduce((sum, item) => sum + (item.quantity || 1), 0)
  const cartCountEl = document.getElementById("cartCount")
  if (cartCountEl) {
    cartCountEl.textContent = totalItems
  }
}

function updateCartDisplay() {
  // Implementation for updating cart display
  const cartItemsContainer = document.getElementById("cartItems")
  if (!cartItemsContainer) return

  if (cart.length === 0) {
    cartItemsContainer.innerHTML = `
      <div class="empty-cart">
        <p>Your cart is empty</p>
      </div>
    `
    return
  }

  cartItemsContainer.innerHTML = cart
    .map(
      (item) => `
    <div class="cart-item">
      <span>${item.name}</span>
      <span>₱${item.price}</span>
      <span>Qty: ${item.quantity}</span>
    </div>
  `,
    )
    .join("")
}


function showLoginModal(){ document.getElementById('loginModal')?.classList.add('active') }
function closeAuthModal(){ document.getElementById('loginModal')?.classList.remove('active'); document.getElementById('registerModal')?.classList.remove('active'); document.getElementById('termsModal')?.classList.remove('active') }
function switchToRegister(){ closeAuthModal(); document.getElementById('registerModal')?.classList.add('active') }
function switchToLogin(){ closeAuthModal(); document.getElementById('loginModal')?.classList.add('active') }
function showEditProfileModal(){ document.getElementById('editProfileModal')?.classList.add('active') }
function closeEditProfileModal(){ document.getElementById('editProfileModal')?.classList.remove('active') }
function logout(e){ if(e && e.preventDefault) e.preventDefault(); window.location.href='logout.php' }
function openCart(){ document.getElementById('cartModal')?.classList.add('open'); document.body.style.overflow='hidden' }
function closeCart(){ document.getElementById('cartModal')?.classList.remove('open'); document.body.style.overflow='auto' }
function closeProductModal(){ document.getElementById('productModal')?.classList.remove('open'); document.body.style.overflow='auto' }
function openTestimonialModal(img){ if(img && img.tagName) { /* simple preview */ const src = img.src || img.getAttribute('data-src'); window.open(src || '#', '_blank') } }
function addProductToCart(){ showNotification('Added to cart (placeholder)','success'); closeProductModal() }