// Формы: свои ошибки под полями вместо подсказок браузера, показ пароля, отправка и окна с формой.

import { el, showStatus } from "./common.js";

function clearError(field) {
  field.closest(".field")?.querySelector(".field__error")?.remove();
  field.removeAttribute("aria-invalid");
}

function showFieldError(field, message) {
  const container = field.closest(".field");
  const error = el("p", { class: "field__error", id: `${field.id}-error` }, message);
  container.append(error);
  field.setAttribute("aria-invalid", "true");
  field.setAttribute("aria-describedby", error.id);
}

// Текст ошибки берём из названия поля; для сложных правил поле хранит свой текст в title.
function errorText(field) {
  const name = field.labels[0].textContent.trim();
  const { validity } = field;
  if (validity.valueMissing && field.type !== "checkbox") {
    return field.tagName === "SELECT" ? `Выбери: ${name}.` : `Заполни поле «${name}».`;
  }
  if (validity.tooShort) {
    return `«${name}»: не короче ${field.minLength} символов.`;
  }
  if (validity.typeMismatch) {
    return "Проверь адрес почты.";
  }
  if (validity.rangeUnderflow || validity.rangeOverflow) {
    return `«${name}»: от ${field.min} до ${field.max}.`;
  }
  return field.title || `Проверь поле «${name}».`;
}

// true, если все поля верны; иначе показывает ошибки и фокусирует первое неверное поле.
function validate(form) {
  let first = null;
  const fail = (field, message) => {
    showFieldError(field, message);
    first ??= field;
  };

  for (const field of form.querySelectorAll("input, select, textarea")) {
    clearError(field);
    if (!field.validity.valid) {
      fail(field, errorText(field));
    }
  }

  // Поле repeat сравнивается с полем пароля перед ним.
  const passwords = [...form.querySelectorAll("input[type=password]")];
  const repeat = form.elements.repeat;
  if (repeat?.value && repeat.value !== passwords[passwords.indexOf(repeat) - 1].value) {
    fail(repeat, "Пароли не совпадают.");
  }

  first?.focus();
  return !first;
}

// Кнопка-глаз переключает поле между password и text.
function addPasswordToggle(wrapper) {
  const field = wrapper.querySelector("input");
  const button = el(
    "button",
    {
      class: "field__toggle",
      type: "button",
      "aria-label": "Показать пароль",
      "aria-controls": field.id,
      "aria-pressed": "false",
      onclick: () => {
        const show = field.type === "password";
        field.type = show ? "text" : "password";
        button.setAttribute("aria-pressed", String(show));
      },
    },
    el("img", { src: "/assets/icons/eye.svg", width: 20, height: 20, alt: "" })
  );
  wrapper.append(button);
}

// Подключает форму: проверка, блокировка кнопок на время запроса, ошибка в [role=alert].
// handler получает FormData; брошенная им ошибка показывается под формой.
export function bindForm(form, handler) {
  const status = form.querySelector("[role=alert]");
  form.noValidate = true;
  form.querySelectorAll(".field__password").forEach(addPasswordToggle);
  form.addEventListener("input", (event) => clearError(event.target));

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!validate(form)) {
      return;
    }
    const buttons = form.querySelectorAll("button:not(.field__toggle)");
    buttons.forEach((button) => (button.disabled = true));
    showStatus(status, "");
    try {
      await handler(new FormData(form));
    } catch (error) {
      showStatus(status, error.message);
    } finally {
      buttons.forEach((button) => (button.disabled = false));
    }
  });
}

let fieldCount = 0;

// Поле формы с подписью: control — input, select или textarea.
export function field(label, control) {
  control.id = `field-${++fieldCount}`;
  return el("div", { class: "field" }, el("label", { for: control.id }, label), control);
}

// Окно с формой (элемент dialog: Esc закрывает, фокус остаётся внутри).
// onSubmit получает FormData; при ошибке окно остаётся открытым и показывает её.
export function openDialog({ title, text, fields = [], submit, onSubmit }) {
  const dialog = el("dialog", { class: "dialog", "aria-labelledby": "dialog-title" });
  const form = el(
    "form",
    { class: "dialog__form" },
    el("h2", { id: "dialog-title" }, title),
    text && el("p", {}, text),
    ...fields,
    el("p", { class: "form__status", role: "alert", hidden: true }),
    el(
      "div",
      { class: "dialog__buttons" },
      el("button", { class: "button button--secondary", type: "button", onclick: () => dialog.close() }, "Отмена"),
      el("button", { class: "button button--main", type: "submit" }, submit)
    )
  );
  dialog.append(form);
  document.body.append(dialog);
  dialog.addEventListener("close", () => dialog.remove());
  bindForm(form, async (data) => {
    await onSubmit(data);
    dialog.close();
  });
  dialog.showModal();
}
