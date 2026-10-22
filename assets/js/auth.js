function obnovitEkran() {
  const hash = location.hash.slice(1);
  const ekran = ["registraciya", "vosstanovlenie"].includes(hash) ? hash : "vhod";
  document.documentElement.dataset.ekran = ekran;
  const perehod = document.querySelector(".skip-link");
  if (perehod) perehod.href = `#${ekran}`;
}

obnovitEkran();
window.addEventListener("hashchange", () => {
  obnovitEkran();
  window.scrollTo(0, 0);
});

document.addEventListener("DOMContentLoaded", () => {
  obnovitEkran();
  document.querySelectorAll(".dostup__forma").forEach((forma) => {
    const status = forma.querySelector(".dostup__status");
    const parol = forma.elements.namedItem("password");
    const povtor = forma.elements.namedItem("password_confirmation");

    function obnovitOshibki() {
      const oshibki = [...forma.querySelectorAll("input:invalid, select:invalid")];
      forma.querySelectorAll("input, select").forEach((pole) => {
        pole.setAttribute("aria-invalid", String(!pole.validity.valid));
      });
      status.hidden = oshibki.length === 0;
      status.classList.toggle("dostup__status--oshibka", oshibki.length > 0);
      let tekst = "Проверь выделенные поля.";
      if (oshibki.some((pole) => pole.validity.valueMissing && pole.type !== "checkbox")) {
        tekst = "Заполни все обязательные поля.";
      } else if (oshibki.some((pole) => pole.validity.typeMismatch)) {
        tekst = "Введи корректный email.";
      } else if (povtor?.validity.customError) {
        tekst = "Пароли не совпадают.";
      } else if (oshibki.some((pole) => pole.validity.tooShort)) {
        tekst = "Пароль должен содержать не менее 8 символов.";
      } else if (oshibki.some((pole) => pole.type === "checkbox")) {
        tekst = "Подтверди согласие с правилами обмена материалами.";
      }
      status.textContent = status.hidden ? "" : tekst;
    }

    // invalid не всплывает, поэтому слушаем его в фазе захвата.
    forma.addEventListener("invalid", (sobytie) => {
      sobytie.preventDefault();
      forma.classList.add("dostup__forma--proverena");
      obnovitOshibki();
    }, true);

    forma.addEventListener("input", () => {
      if (povtor) {
        povtor.setCustomValidity(povtor.value === parol.value ? "" : "Пароли не совпадают.");
      }
      if (forma.classList.contains("dostup__forma--proverena")) obnovitOshibki();
    });

    // До подключения PHP предотвращаем отправку данных и не имитируем успешный вход.
    forma.addEventListener("submit", (sobytie) => {
      sobytie.preventDefault();
      status.classList.remove("dostup__status--oshibka");
      status.textContent = "Поля заполнены. Сервер ещё не подключён: данные не отправлены, действие не выполнено.";
      status.hidden = false;
    });

    forma.querySelector('[type="submit"]').disabled = false;
  });

  document.querySelectorAll("[data-parol]").forEach((button) => {
    button.addEventListener("click", () => {
      const pole = document.getElementById(button.getAttribute("aria-controls"));
      const pokazat = pole.type === "password";
      pole.type = pokazat ? "text" : "password";
      button.setAttribute("aria-pressed", String(pokazat));
      button.setAttribute("aria-label", pokazat ? "Скрыть пароль" : "Показать пароль");
    });
  });
});
