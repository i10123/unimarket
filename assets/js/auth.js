// Страница входа и регистрации: выбор формы, показ пароля и отправка в PHP.
// Файл подключён в head без defer: экран выбирается до отрисовки, без мигания.

let csrf = "";

// #registraciya в адресе показывает регистрацию, иначе — вход.
function obnovitEkran() {
  const ekran = location.hash === "#registraciya" ? "registraciya" : "vhod";
  document.documentElement.dataset.ekran = ekran;

  const perehod = document.querySelector(".skip-link");
  if (perehod) {
    perehod.href = `#${ekran}`;
  }
}

function pokazatOshibku(forma, tekst) {
  const status = forma.querySelector("[role=status]");
  status.textContent = tekst;
  status.hidden = false;
}

// Кнопка-глаз переключает поле между password и text.
function pereklyuchitParol(button) {
  const pole = document.getElementById(button.getAttribute("aria-controls"));
  const pokazat = pole.type === "password";

  pole.type = pokazat ? "text" : "password";
  button.setAttribute("aria-pressed", String(pokazat));
  button.setAttribute("aria-label", pokazat ? "Скрыть пароль" : "Показать пароль");
}

async function otpravitFormu(sobytie) {
  sobytie.preventDefault();
  const forma = sobytie.currentTarget;
  const button = forma.querySelector("[type=submit]");

  button.disabled = true;
  forma.setAttribute("aria-busy", "true");
  forma.querySelector("[role=status]").hidden = true;

  try {
    const response = await fetch("/api/auth", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrf,
      },
      body: JSON.stringify({
        action: forma.dataset.action,
        login: forma.elements.login.value,
        password: forma.elements.password.value,
      }),
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error);
    }
    location.replace("/pages/catalog.html");
  } catch (error) {
    pokazatOshibku(forma, error.message || "Сервер недоступен. Попробуй ещё раз.");
  } finally {
    button.disabled = false;
    forma.removeAttribute("aria-busy");
  }
}

// Получаем CSRF-токен; без него сервер не примет форму, поэтому кнопки
// включаются только после ответа.
async function podgotovitFormy(formy) {
  try {
    const response = await fetch("/api/auth");
    if (!response.ok) {
      throw new Error();
    }
    const data = await response.json();
    if (data.user) {
      location.replace("/pages/catalog.html");
      return;
    }
    csrf = data.csrf;
    formy.forEach((forma) => {
      forma.querySelector("[type=submit]").disabled = false;
    });
  } catch {
    const tekst = "Сервер недоступен. Включи Open Server Panel, открой http://unimarket.local и обнови страницу.";
    formy.forEach((forma) => {
      pokazatOshibku(forma, tekst);
    });
  }
}

obnovitEkran();

window.addEventListener("hashchange", () => {
  obnovitEkran();
  window.scrollTo(0, 0);
});

document.addEventListener("DOMContentLoaded", () => {
  const formy = document.querySelectorAll(".dostup__forma");

  document.querySelectorAll("[data-parol]").forEach((button) => {
    button.addEventListener("click", () => {
      pereklyuchitParol(button);
    });
  });
  formy.forEach((forma) => {
    forma.addEventListener("submit", otpravitFormu);
  });

  podgotovitFormy(formy);
});
