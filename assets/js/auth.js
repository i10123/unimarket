function obnovitEkran() {
  const ekran = location.hash === "#registraciya" ? "registraciya" : "vhod";
  document.documentElement.dataset.ekran = ekran;
  const perehod = document.querySelector(".skip-link");
  if (perehod) perehod.href = `#${ekran}`;
}
obnovitEkran();
window.addEventListener("hashchange", () => {
  obnovitEkran();
  window.scrollTo(0, 0);
});

document.addEventListener("DOMContentLoaded", async () => {
  obnovitEkran();
  let csrf;
  const formy = document.querySelectorAll(".dostup__forma");
  function pokazatOshibku(forma, tekst) {
    const status = forma.querySelector("[role=status]");
    status.textContent = tekst;
    status.hidden = false;
  }
  document.querySelectorAll("[data-parol]").forEach((button) => {
    button.addEventListener("click", () => {
      const pole = document.getElementById(button.getAttribute("aria-controls"));
      const pokazat = pole.type === "password";
      pole.type = pokazat ? "text" : "password";
      button.setAttribute("aria-pressed", String(pokazat));
      button.setAttribute("aria-label", pokazat ? "Скрыть пароль" : "Показать пароль");
    });
  });
  formy.forEach((forma) => {
    forma.addEventListener("submit", async (sobytie) => {
      sobytie.preventDefault();
      const button = forma.querySelector("[type=submit]");
      button.disabled = true;
      forma.setAttribute("aria-busy", "true");
      forma.querySelector("[role=status]").hidden = true;
      try {
        const response = await fetch("/api/auth", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
          body: JSON.stringify({ action: forma.dataset.action,
            email: forma.elements.email.value, password: forma.elements.password.value }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error);
        location.replace("/pages/catalog.html");
      } catch (error) {
        pokazatOshibku(forma, error.message || "Сервер недоступен. Попробуй ещё раз.");
      } finally {
        button.disabled = false;
        forma.removeAttribute("aria-busy");
      }
    });
  });
  try {
    const response = await fetch("/api/auth");
    if (!response.ok) throw new Error();
    const data = await response.json();
    if (data.user) return location.replace("/pages/catalog.html");
    csrf = data.csrf;
    formy.forEach((forma) => { forma.querySelector("[type=submit]").disabled = false; });
  } catch {
    formy.forEach((forma) => pokazatOshibku(forma, "Сервер недоступен. Открой сайт через http://127.0.0.1:8000 и обнови страницу."));
  }
});
