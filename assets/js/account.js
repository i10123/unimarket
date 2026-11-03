document.addEventListener("DOMContentLoaded", async () => {
  const status = document.getElementById("status");
  const button = document.getElementById("viyti");
  try {
    const response = await fetch("/api/auth");
    const data = await response.json();
    if (!response.ok) throw new Error(data.error);
    if (!data.user) return location.replace("/pages/auth/login.html");
    document.getElementById("email").textContent = data.user.email;
    document.getElementById("balance").textContent = data.user.available;
    document.getElementById("reserve").textContent = data.user.reserved;
    status.textContent = "Каталог пока пуст.";
    button.disabled = false;
    button.addEventListener("click", async () => {
      button.disabled = true;
      try {
        const response = await fetch("/api/auth", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": data.csrf },
          body: JSON.stringify({ action: "logout" }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error);
        location.replace("/pages/auth/login.html");
      } catch (error) {
        status.textContent = error.message || "Не удалось выйти. Попробуй снова.";
        button.disabled = false;
      }
    });
  } catch (error) {
    status.textContent = error.message || "Сервер недоступен. Обнови страницу.";
  }
});
