// Jahr im Footer
const yearEl = document.getElementById("y");
if (yearEl) yearEl.textContent = new Date().getFullYear();

const form = document.getElementById("contactForm");
const hint = document.getElementById("formHint");

function setHint(text, isOk = true) {
  if (!hint) return;
  hint.textContent = text;
  hint.style.color = isOk ? "var(--muted)" : "color-mix(in srgb, var(--primary) 65%, var(--text))";
}

if (form && hint) {
  setHint("Hinweis: Deine Nachricht wird direkt über unseren Server gesendet (kein Mailprogramm nötig).");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    setHint("Senden …");

    try {
      const formData = new FormData(form);

      const res = await fetch(form.action, {
        method: "POST",
        body: formData,
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);

      if (!res.ok || !data || !data.ok) {
        setHint((data && data.message) ? data.message : "Senden fehlgeschlagen. Bitte später erneut versuchen.", false);
      } else {
        setHint(data.message || "Gesendet ✅", true);
        form.reset();
      }
    } catch {
      setHint("Technischer Fehler. Bitte später erneut versuchen oder anrufen.", false);
    } finally {
      if (btn) btn.disabled = false;
    }
  });
}

const header = document.querySelector("header");

function updateHeaderOffset() {
  if (!header) return;
  const headerHeight = Math.ceil(header.getBoundingClientRect().height);
  document.documentElement.style.setProperty("--header-offset", `${headerHeight}px`);
}

if (header) {
  updateHeaderOffset();
  window.addEventListener("load", updateHeaderOffset);
  window.addEventListener("resize", updateHeaderOffset);

  if ("ResizeObserver" in window) {
    const headerObserver = new ResizeObserver(updateHeaderOffset);
    headerObserver.observe(header);
  }
}
