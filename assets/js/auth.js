// Страница входа и регистрации: выбор экрана и шага, проверка полей, показ пароля и отправка в PHP.
// Файл подключён в head без defer: экран выбирается до отрисовки, без мигания.

const VTOROY_SHAG = "#registraciya-2";

let csrf = "";
// Второй шаг открывается только после проверки первого.
let pervyyShagProyden = false;

// Экран и шаг задаёт адрес, поэтому «назад» в браузере и на мыши ходит между шагами.
function obnovitEkran() {
  if (location.hash === VTOROY_SHAG && !pervyyShagProyden) {
    history.replaceState(null, "", "#registraciya");
  }

  const registraciya = location.hash === "#registraciya" || location.hash === VTOROY_SHAG;
  const ekran = registraciya ? "registraciya" : "vhod";
  document.documentElement.dataset.ekran = ekran;
  document.documentElement.dataset.shag = location.hash === VTOROY_SHAG ? "2" : "1";

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

function pokazatOshibkuPolya(pole, tekst) {
  const blok = pole.closest(".pole");
  let oshibka = blok.querySelector(".pole__oshibka");

  if (!oshibka) {
    oshibka = document.createElement("p");
    oshibka.className = "pole__oshibka";
    oshibka.id = `${pole.id}-oshibka`;
    blok.append(oshibka);
    const opisanie = pole.getAttribute("aria-describedby");
    pole.setAttribute("aria-describedby", opisanie ? `${opisanie} ${oshibka.id}` : oshibka.id);
  }

  oshibka.textContent = tekst;
  oshibka.hidden = false;
  pole.setAttribute("aria-invalid", "true");
}

function ubratOshibkuPolya(pole) {
  const oshibka = pole.closest(".pole").querySelector(".pole__oshibka");
  if (oshibka) {
    oshibka.hidden = true;
  }
  pole.removeAttribute("aria-invalid");
}

// Подсказки браузера выключены (novalidate), тексты ошибок лежат в data-атрибутах полей.
function proveritFormu(forma) {
  let pervoePole = null;

  forma.querySelectorAll("input, select").forEach((pole) => {
    ubratOshibkuPolya(pole);
    if (!pole.validity.valid) {
      pokazatOshibkuPolya(pole, pole.validity.valueMissing ? pole.dataset.pusto : pole.dataset.format);
      pervoePole ??= pole;
    }
  });

  const povtor = forma.elements.repeat;
  if (povtor && povtor.value && povtor.value !== forma.elements.password.value) {
    pokazatOshibkuPolya(povtor, "Пароли не совпадают.");
    pervoePole ??= povtor;
  }

  if (pervoePole) {
    pervoePole.focus();
  }
  return !pervoePole;
}

// Кнопка-глаз переключает поле между password и text.
function pereklyuchitParol(button) {
  const pole = document.getElementById(button.getAttribute("aria-controls"));
  const pokazat = pole.type === "password";

  pole.type = pokazat ? "text" : "password";
  button.setAttribute("aria-pressed", String(pokazat));
  button.setAttribute("aria-label", pokazat ? "Скрыть пароль" : "Показать пароль");
}

async function otpravit(dannye) {
  const response = await fetch("/api/auth", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrf,
    },
    body: JSON.stringify(dannye),
  });
  const data = await response.json();
  if (!response.ok) {
    const oshibka = new Error(data.error);
    oshibka.status = response.status;
    throw oshibka;
  }
}

function otkrytKatalog() {
  location.replace("/pages/catalog.html");
}

async function voyti(forma) {
  await otpravit({
    action: "login",
    login: forma.elements.login.value,
    password: forma.elements.password.value,
  });
  otkrytKatalog();
}

async function proveritLogin(forma) {
  await otpravit({ action: "check_login", login: forma.elements.login.value });
  pervyyShagProyden = true;
  location.hash = VTOROY_SHAG;
}

async function zaregistrirovatsya(sProfilem) {
  const dostup = document.getElementById("shag-dostup").elements;
  const profil = Object.fromEntries(new FormData(document.getElementById("shag-profil")));

  await otpravit({
    action: "register",
    login: dostup.login.value,
    password: dostup.password.value,
    rules: dostup.rules.checked,
    ...(sProfilem ? profil : {}),
  });
  otkrytKatalog();
}

// Общая обёртка отправки: блокирует кнопки и показывает ошибку.
async function vypolnit(forma, deystvie) {
  const knopki = forma.querySelectorAll("button:not([data-parol])");

  knopki.forEach((button) => {
    button.disabled = true;
  });
  forma.setAttribute("aria-busy", "true");
  forma.querySelector("[role=status]").hidden = true;

  try {
    await deystvie();
  } catch (error) {
    if (error.status === 409) {
      // Логин заняли, пока заполнялся второй шаг: возвращаем к полю логина.
      const pole = document.getElementById("registraciya-login");
      location.hash = "#registraciya";
      pokazatOshibkuPolya(pole, error.message);
      pole.focus();
      return;
    }
    // Обычный Error создаём сами из ответа сервера; у сбоя сети текст английский.
    const svoyTekst = error.name === "Error" && error.message;
    pokazatOshibku(forma, svoyTekst || "Сервер недоступен. Попробуй ещё раз.");
  } finally {
    knopki.forEach((button) => {
      button.disabled = false;
    });
    forma.removeAttribute("aria-busy");
  }
}

const DEYSTVIYA = {
  login: voyti,
  check_login: proveritLogin,
  register: () => zaregistrirovatsya(true),
};

function otpravitFormu(sobytie) {
  sobytie.preventDefault();
  const forma = sobytie.currentTarget;

  if (proveritFormu(forma)) {
    vypolnit(forma, () => DEYSTVIYA[forma.dataset.action](forma));
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
      otkrytKatalog();
      return;
    }
    csrf = data.csrf;
    document.querySelectorAll(".dostup__forma button:not([data-parol])").forEach((button) => {
      button.disabled = false;
    });
  } catch {
    const tekst = "Сервер недоступен. Обнови страницу позже.";
    formy.forEach((forma) => {
      pokazatOshibku(forma, tekst);
    });
  }
}

obnovitEkran();

window.addEventListener("hashchange", (sobytie) => {
  obnovitEkran();
  window.scrollTo(0, 0);

  if (location.hash === VTOROY_SHAG) {
    document.getElementById("profil-imya").focus();
  } else if (sobytie.oldURL.endsWith(VTOROY_SHAG)) {
    document.getElementById("registraciya-login").focus();
  }
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
    // Ошибка поля исчезает, как только его начали исправлять.
    forma.addEventListener("input", (sobytie) => {
      ubratOshibkuPolya(sobytie.target);
    });
  });

  const profil = document.getElementById("shag-profil");
  document.getElementById("propustit").addEventListener("click", () => {
    vypolnit(profil, () => zaregistrirovatsya(false));
  });

  podgotovitFormy(formy);
});
