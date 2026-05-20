(function () {
  "use strict";

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.from((root || document).querySelectorAll(selector));
  }

  function initSpinner() {
    const spinner = qs("#spinner");
    if (!spinner) return;
    window.setTimeout(function () {
      spinner.classList.remove("show", "opacity-100", "visible");
      spinner.classList.add("opacity-0", "invisible");
    }, 100);
  }

  function initWow() {
    if (window.WOW) {
      new window.WOW().init();
    }
  }

  function initStickyNav() {
    const nav = qs("[data-sticky-nav]");
    if (!nav) return;
    function updateNav() {
      if (window.scrollY > 300) {
        nav.classList.add("shadow-sm", "top-0");
      } else {
        nav.classList.remove("shadow-sm");
      }
    }
    updateNav();
    window.addEventListener("scroll", updateNav, { passive: true });
  }

  function initMobileMenu() {
    const toggle = qs("[data-mobile-menu-toggle]");
    const menu = qs("[data-mobile-menu]");
    if (!toggle || !menu) return;

    function setOpen(isOpen) {
      toggle.setAttribute("aria-expanded", String(isOpen));
      menu.classList.toggle("hidden", !isOpen);
    }

    toggle.addEventListener("click", function () {
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });

    function syncFromViewport() {
      if (window.innerWidth >= 1024) {
        setOpen(true);
      } else {
        setOpen(false);
      }
    }

    window.addEventListener("resize", syncFromViewport);
    syncFromViewport();
  }

  function initProjectsDropdown() {
    const toggle = qs("[data-projects-toggle]");
    const menu = qs("[data-projects-menu]");
    if (!toggle || !menu) return;

    function setOpen(isOpen) {
      toggle.setAttribute("aria-expanded", String(isOpen));
      menu.classList.toggle("hidden", !isOpen);
    }

    toggle.addEventListener("click", function (event) {
      event.preventDefault();
      if (window.innerWidth >= 1024) return;
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });

    document.addEventListener("click", function (event) {
      if (!toggle.contains(event.target) && !menu.contains(event.target)) {
        setOpen(false);
      }
    });
  }

  function initActiveLinks() {
    const fileName = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();
    qsa(".nav-link").forEach(function (link) {
      const href = (link.getAttribute("href") || "").toLowerCase();
      link.classList.toggle("active", href === fileName);
    });
  }

  function initBackToTop() {
    const button = qs(".back-to-top");
    if (!button) return;
    function updateButton() {
      button.classList.toggle("hidden", window.scrollY <= 300);
      button.classList.toggle("inline-flex", window.scrollY > 300);
    }
    button.addEventListener("click", function (event) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
    updateButton();
    window.addEventListener("scroll", updateButton, { passive: true });
  }

  function initProgressBars() {
    qsa(".progress-bar").forEach(function (bar) {
      const value = Number(bar.getAttribute("aria-valuenow") || "0");
      const safe = Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
      bar.style.width = safe + "%";
    });
  }

  function initCounters() {
    if (!window.jQuery) return;
    const $ = window.jQuery;
    if (typeof $.fn.counterUp === "function") {
      $("[data-toggle=\"counter-up\"]").counterUp({
        delay: 10,
        time: 2000
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    initSpinner();
    initWow();
    initStickyNav();
    initMobileMenu();
    initProjectsDropdown();
    initActiveLinks();
    initBackToTop();
    initProgressBars();
    initCounters();
  });
})();
