// Каталог: показывает имя, логин и остатки вошедшего пользователя, кнопка «Выйти».

const message = document.getElementById("status");
const logoutButton = document.getElementById("logout");

// Обычный Error создаём сами из ответа сервера; у сбоя сети текст английский.
function getErrorMessage(error, fallbackMessage) {
  return (error.name === "Error" && error.message) || fallbackMessage;
}

function displayAccount(user) {
  document.getElementById("display-name").textContent = user.display_name;
  document.getElementById("login").textContent = user.login;
  document.getElementById("balance").textContent = user.available;
  document.getElementById("reserve").textContent = user.reserved;
}

async function logout(csrf) {
  logoutButton.disabled = true;
  try {
    const response = await fetch("/api/auth", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrf,
      },
      body: JSON.stringify({ action: "logout" }),
    });
    const result = await response.json();
    if (!response.ok) {
      throw new Error(result.error);
    }
    location.replace("/pages/auth/login.html");
  } catch (error) {
    message.textContent = getErrorMessage(error, "Не удалось выйти. Попробуй снова.");
    logoutButton.disabled = false;
  }
}

async function loadAccount() {
  try {
    const response = await fetch("/api/auth");
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error);
    }
    // Сессия закончилась — возвращаем на вход.
    if (!data.user) {
      location.replace("/pages/auth/login.html");
      return;
    }

    displayAccount(data.user);
    message.textContent = "Каталог пока пуст.";
    logoutButton.disabled = false;
    logoutButton.addEventListener("click", () => {
      logout(data.csrf);
    });
  } catch (error) {
    message.textContent = getErrorMessage(error, "Сервер недоступен. Обнови страницу.");
  }
}

loadAccount();
