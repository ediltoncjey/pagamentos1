(function () {
  const config = window.SistemCheckout || {};
  const statusPrefix = typeof config.statusPrefix === "string" ? config.statusPrefix : "/checkout/status/";
  const form = document.getElementById("checkout-form");
  const statusBox = document.getElementById("checkout-status") || document.getElementById("status-message");
  const submitButton = document.getElementById("checkout-submit");
  const deliveryContainer = document.getElementById("delivery-container");
  let pollTimer = null;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function paint(type, message) {
    if (!statusBox) return;
    statusBox.classList.remove("checkout-status--idle", "checkout-status--processing", "checkout-status--paid", "checkout-status--failed");
    statusBox.classList.add("checkout-status--" + type);
    statusBox.innerHTML = escapeHtml(message);
  }

  function renderDelivery(delivery) {
    if (!deliveryContainer) return;
    if (!delivery || !delivery.url) {
      deliveryContainer.innerHTML = "";
      return;
    }

    let extra = "";
    if (delivery.expires_at) {
      extra = " (expira: " + escapeHtml(delivery.expires_at) + ")";
    }
    deliveryContainer.innerHTML = '<a href="' + encodeURI(delivery.url) + '">Aceder ao produto</a>' + extra;
  }

  function mapType(orderStatus, paymentStatus) {
    if (orderStatus === "paid" || paymentStatus === "confirmed") return "paid";
    if (orderStatus === "failed" || paymentStatus === "failed" || paymentStatus === "timeout") return "failed";
    return "processing";
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function poll(orderNo) {
    fetch(statusPrefix + encodeURIComponent(orderNo), {
      method: "GET",
      headers: { Accept: "application/json" },
    })
      .then(async function (response) {
        const payload = await response.json();
        if (!response.ok) {
          throw new Error(payload.error || "Falha ao consultar estado");
        }
        const type = mapType(payload.order_status, payload.payment_status);
        paint(type, payload.message || "Estado atualizado.");
        renderDelivery(payload.delivery || null);
        if (type === "paid" || type === "failed") {
          stopPolling();
        }
      })
      .catch(function (error) {
        paint("failed", error.message || "Erro ao consultar estado");
        stopPolling();
      });
  }

  function startPolling(orderNo) {
    stopPolling();
    poll(orderNo);
    pollTimer = setInterval(function () {
      poll(orderNo);
    }, 5000);
  }

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (submitButton) submitButton.disabled = true;
      paint("processing", "A iniciar pagamento...");

      const payload = new FormData(form);
      fetch(form.action, {
        method: "POST",
        headers: { Accept: "application/json" },
        body: payload,
      })
        .then(async function (response) {
          const data = await response.json();
          if (!response.ok) {
            throw new Error(data.error || "Checkout nao concluido");
          }

          const paymentStatus = data.payment && data.payment.provider_status ? data.payment.provider_status : "processing";
          const orderNo = data.order && data.order.order_no ? data.order.order_no : null;
          if (!orderNo) {
            throw new Error("Resposta invalida do servidor");
          }

          if (paymentStatus === "failed") {
            paint("failed", data.payment.error || "Falha ao iniciar pagamento");
            return;
          }

          paint("processing", "Pedido criado. Estamos a confirmar o pagamento.");
          startPolling(orderNo);
        })
        .catch(function (error) {
          paint("failed", error.message || "Erro no checkout");
        })
        .finally(function () {
          if (submitButton) submitButton.disabled = false;
        });
    });
  } else if (typeof config.orderNo === "string" && config.orderNo !== "") {
    paint("processing", "A acompanhar estado do pagamento...");
    startPolling(config.orderNo);
  }
})();

