(function () {
  "use strict";

  function qs(selector, root = document) {
    return root.querySelector(selector);
  }

  function qsa(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
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
        nav.classList.remove("-top-24");
      } else {
        nav.classList.remove("shadow-sm", "top-0");
        nav.classList.add("-top-24");
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

    window.addEventListener("resize", function () {
      if (window.innerWidth >= 1024) {
        setOpen(true);
      } else {
        setOpen(false);
      }
    });

    setOpen(window.innerWidth >= 1024);
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
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });

    document.addEventListener("click", function (event) {
      if (!toggle.contains(event.target) && !menu.contains(event.target)) {
        setOpen(false);
      }
    });

    setOpen(false);
  }

  function initHeroCarousel() {
    const root = qs("[data-hero-carousel]");
    if (!root) return;

    const slides = qsa("[data-hero-slide]", root);
    const indicators = qsa("[data-hero-indicator]", root);
    if (slides.length === 0) return;

    let activeIndex = Math.max(0, slides.findIndex((slide) => slide.dataset.active === "true"));
    let timer = null;

    function showSlide(index) {
      activeIndex = (index + slides.length) % slides.length;

      slides.forEach(function (slide, slideIndex) {
        const isActive = slideIndex === activeIndex;
        slide.classList.toggle("opacity-100", isActive);
        slide.classList.toggle("opacity-0", !isActive);
        slide.classList.toggle("pointer-events-auto", isActive);
        slide.classList.toggle("pointer-events-none", !isActive);
        slide.dataset.active = String(isActive);
      });

      indicators.forEach(function (indicator, indicatorIndex) {
        indicator.classList.toggle("border-sodap-600", indicatorIndex === activeIndex);
        indicator.classList.toggle("border-white", indicatorIndex !== activeIndex);
        indicator.setAttribute("aria-current", indicatorIndex === activeIndex ? "true" : "false");
      });
    }

    function restart() {
      window.clearInterval(timer);
      timer = window.setInterval(function () {
        showSlide(activeIndex + 1);
      }, 5500);
    }

    indicators.forEach(function (indicator, index) {
      indicator.addEventListener("click", function () {
        showSlide(index);
        restart();
      });
    });

    showSlide(activeIndex);
    restart();
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

  document.addEventListener("DOMContentLoaded", function () {
    initSpinner();
    initWow();
    initStickyNav();
    initMobileMenu();
    initProjectsDropdown();
    initHeroCarousel();
    initBackToTop();
  });
})();
