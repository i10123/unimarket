// Общее для страниц после входа: запросы к API, создание элементов, форматы, шапка и меню кабинета.

export const TYPES = { notes: "Конспект", solutions: "Решённые задачи", cheatsheet: "Шпаргалка" };

// Варианты для select «Курс»: 1–6.
export const courseOptions = () => [1, 2, 3, 4, 5, 6].map((n) => new Option(`${n} курс`, n));

let csrf = "";

// GET без данных, иначе POST: JSON или FormData (для файлов). Ошибки приходят обычным Error по-русски.
export async function api(url, data) {
  const options = {};
  if (data) {
    options.method = "POST";
    options.headers = { "X-CSRF-Token": csrf };
    if (data instanceof FormData) {
      options.body = data;
    } else {
      options.headers["Content-Type"] = "application/json";
      options.body = JSON.stringify(data);
    }
  }

  let response;
  try {
    response = await fetch(url, options);
  } catch {
    throw new Error("Сервер недоступен. Проверь соединение и повтори.");
  }
  // Не JSON бывает, когда PHP отвечает предупреждением до запуска скрипта (например, файлы больше post_max_size).
  const result = await response.json().catch(() => null);
  if (!result || !response.ok) {
    const message = result ? result.error : "Сервер ответил неожиданно. Возможно, файлы слишком большие.";
    throw Object.assign(new Error(message || "Не удалось выполнить действие."), { status: response.status });
  }
  csrf = result.csrf || csrf;
  return result;
}

// Создаёт элемент. Текст вставляется как текст, а не как HTML, поэтому пользовательские данные безопасны.
export function el(tag, attributes = {}, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (name.startsWith("on")) {
      node.addEventListener(name.slice(2), value);
    } else if (value !== false && value != null) {
      node.setAttribute(name, value === true ? "" : value);
    }
  }
  node.append(...children.flat().filter((child) => child != null && child !== false));
  return node;
}

export const plural = (n, forms) => forms[n % 10 === 1 && n % 100 !== 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2];
export const points = (n) => `${n} ${plural(Math.abs(n), ["балл", "балла", "баллов"])}`;
export const price = (n, free = "бесплатно") => (n ? points(n) : free);
export const rating = (average, votes) => (votes > 0 ? `★ ${String(average).replace(".", ",")}` : "Нет оценок");
export const fileSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} КБ` : `${(bytes / 1024 / 1024).toFixed(1).replace(".", ",")} МБ`);

// Время в БД хранится по UTC («2026-11-10 12:00:00»), показываем по местному.
export const formatDate = (value) =>
  new Date(value.replace(" ", "T") + "Z").toLocaleString("ru-RU", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

// Ссылка на скачивание файла.
export const downloadLink = (file) =>
  el("a", { class: "file-link", href: `/api/download?id=${file.id}` }, `${file.name} · ${fileSize(file.size)}`);

// Кнопка действия для строк списков.
export const button = (title, onclick, kind = "secondary") =>
  el("button", { class: `button button--${kind}`, type: "button", onclick }, title);

// Заполняет select предмета, курса и типа в форме, если они в ней есть. Предметы приходят с сервера.
export async function fillSelects(form) {
  const { subjects } = await api("/api/market?subjects");
  form.elements.subject.append(...subjects.map((subject) => new Option(subject, subject)));
  form.elements.course.append(...courseOptions());
  form.elements.type.append(...Object.entries(TYPES).map(([value, title]) => new Option(title, value)));
}

// Сообщение под формой или списком: ошибка или спокойный текст.
export function showStatus(node, text, isError = true) {
  node.textContent = text;
  node.hidden = !text;
  node.classList.toggle("form__status--ok", !isError);
}

const MENU = [
  ["account", "Общее", "/pages/account.html"],
  ["purchases", "Мои покупки", "/pages/purchases.html"],
  ["sales", "Мои продажи", "/pages/sales.html"],
  ["settings", "Настройки", "/pages/settings.html"],
];

// Запуск страницы: проверяем вход, рисуем шапку и подвал. Возвращает пользователя.
// Гостя сервер не пустит на страницу, но сессия могла закончиться, пока она была открыта.
export async function startPage(menuItem) {
  const { user } = await api("/api/auth").catch((error) => {
    document.body.textContent = error.message;
    throw error;
  });
  if (!user) {
    location.replace("/pages/auth/login.html");
    throw new Error("Нужно войти");
  }

  document.querySelector(".site-header").innerHTML = `
    <div class="site-header__vnutri container">
      <a class="brand" href="/pages/catalog.html" aria-label="УниМаркет — каталог">
        <img src="/assets/icons/logo.svg" width="40" height="40" alt="">
        <span>УниМаркет</span>
      </a>
      <nav class="app-nav" aria-label="Основное меню">
        <a href="/pages/favorites.html" aria-label="Избранное"><img src="/assets/icons/heart-outline.svg" width="24" height="24" alt=""><span>Избранное</span></a>
        <a href="/pages/account.html" aria-label="Мой профиль"><img src="/assets/icons/user.svg" width="24" height="24" alt=""><span>Мой профиль</span></a>
        <p class="app-nav__balance"><strong>${points(user.available)}</strong><span>Баланс</span></p>
        <a class="button button--main" href="/pages/publish.html" aria-label="Разместить материал">+<span> Разместить</span></a>
      </nav>
    </div>`;
  document.querySelector(".site-footer").innerHTML = `
    <div class="site-footer__vnutri container"><a class="brand" href="/pages/catalog.html">УниМаркет</a></div>`;

  if (menuItem) {
    document.querySelector(".sidebar").replaceChildren(
      ...MENU.map(([id, title, href]) => el("a", { href, "aria-current": id === menuItem ? "page" : null }, title)),
      el("button", { type: "button", onclick: logout }, "Выйти из аккаунта")
    );
  }
  return user;
}

async function logout() {
  await api("/api/auth", { action: "logout" });
  location.replace("/pages/auth/login.html");
}
