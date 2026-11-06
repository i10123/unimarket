// Каталог: показывает логин и остатки вошедшего пользователя, кнопка «Выйти».

const soobshenie = document.getElementById("status");
const vyitiButton = document.getElementById("viyti");

function pokazatAkkaunt(user) {
  document.getElementById("login").textContent = user.login;
  document.getElementById("balance").textContent = user.available;
  document.getElementById("reserve").textContent = user.reserved;
}

async function vyiti(csrf) {
  vyitiButton.disabled = true;
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
    soobshenie.textContent = error.message || "Не удалось выйти. Попробуй снова.";
    vyitiButton.disabled = false;
  }
}

async function zagruzitAkkaunt() {
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

    pokazatAkkaunt(data.user);
    soobshenie.textContent = "Каталог пока пуст.";
    vyitiButton.disabled = false;
    vyitiButton.addEventListener("click", () => {
      vyiti(data.csrf);
    });
  } catch (error) {
    soobshenie.textContent = error.message || "Сервер недоступен. Обнови страницу.";
  }
}

zagruzitAkkaunt();
