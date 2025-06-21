function showReceiptModal(referenceNumber) {
  // Remove any existing modal
  let existing = document.getElementById("receiptModal");
  if (existing) existing.remove();

  const modal = document.createElement("div");
  modal.id = "receiptModal";
  modal.style.position = "fixed";
  modal.style.top = "0";
  modal.style.left = "0";
  modal.style.width = "100vw";
  modal.style.height = "100vh";
  modal.style.background = "rgba(0,0,0,0.5)";
  modal.style.display = "flex";
  modal.style.alignItems = "center";
  modal.style.justifyContent = "center";
  modal.style.zIndex = "9999";

  modal.innerHTML = `
    <div style="background:#fff;padding:32px 24px;border-radius:18px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,0.18);text-align:center;position:relative;">
      <button onclick="document.getElementById('receiptModal').remove()" style="position:absolute;top:12px;right:18px;background:none;border:none;font-size:2rem;cursor:pointer;">&times;</button>
      <h2 style="color:#059669;margin-bottom:12px;">Order Placed!</h2>
      <div style="font-size:1.2rem;margin-bottom:18px;">Thank you for your order.</div>
      <div style="font-size:1.1rem;margin-bottom:10px;">Reference Number:</div>
      <div style="font-size:2rem;font-weight:700;color:#40534b;margin-bottom:18px;">${referenceNumber}</div>
      <div style="font-size:1rem;color:#6b7280;">Please screenshot this receipt for your records.</div>
    </div>
  `;
  document.body.appendChild(modal);
}
