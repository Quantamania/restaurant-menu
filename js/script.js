/* Maridadi Eat — all the JavaScript for the site */

(function () {
  "use strict";

  /* 1. SIDEBAR */

  var navToggle = document.querySelector(".topbar__toggle");
  var sidebar   = document.getElementById("sidebar");
  var scrim     = document.querySelector("[data-scrim]");

  if (navToggle && sidebar) {
    var setOpen = function (open) {
      navToggle.setAttribute("aria-expanded", String(open));
      sidebar.classList.toggle("is-open", open);
      if (scrim) { scrim.hidden = !open; }
      document.body.style.overflow = open ? "hidden" : "";
    };

    navToggle.addEventListener("click", function () {
      setOpen(navToggle.getAttribute("aria-expanded") !== "true");
    });

    if (scrim) {
      scrim.addEventListener("click", function () { setOpen(false); });
    }

    /* Close after tapping a link, so the page behind is visible */
    sidebar.addEventListener("click", function (event) {
      if (event.target.closest("a")) { setOpen(false); }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && sidebar.classList.contains("is-open")) {
        setOpen(false);
        navToggle.focus();
      }
    });

    /* Dragging the window wide again must not leave the page scroll-locked. */
    window.addEventListener("resize", function () {
      if (window.innerWidth > 960 && sidebar.classList.contains("is-open")) {
        setOpen(false);
      }
    });
  }

  /* Pulling the rail in and out on a wide screen. The choice is remembered, */
  var railToggle = document.querySelector("[data-rail-toggle]");

  if (railToggle) {
    var CLOSED = "rail-closed";

    var setRail = function (closed, remember) {
      document.body.classList.toggle("is-rail-closed", closed);
      railToggle.setAttribute("aria-expanded", String(!closed));
      railToggle.setAttribute("aria-label", closed ? "Show the menu" : "Hide the menu");
      if (remember) {
        try { window.localStorage.setItem(CLOSED, closed ? "1" : "0"); } catch (e) {}
      }
    };

    var stored = null;
    try { stored = window.localStorage.getItem(CLOSED); } catch (e) {}
    if (stored === "1") { setRail(true, false); }

    railToggle.addEventListener("click", function () {
      setRail(!document.body.classList.contains("is-rail-closed"), true);
    });
  }

  /* 2. RESERVATION FORM */

  /* Stop the date picker offering days that have already passed */
  var date = document.getElementById("reservation_date");
  if (date && !date.min) {
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    date.min = now.toISOString().slice(0, 10);
  }

  /* Count the characters left in the message box */
  var message = document.getElementById("message");
  var counter = document.querySelector('[data-counter-for="message"]');

  if (message && counter) {
    var limit = parseInt(message.getAttribute("maxlength"), 10) || 500;

    var render = function () {
      counter.textContent = message.value.length + " / " + limit;
    };

    message.addEventListener("input", render);
    render();
  }

  /* Stop the form being submitted twice by an impatient double-click */
  var form = document.querySelector(".reservation-form");
  if (form) {
    form.addEventListener("submit", function () {
      var button = form.querySelector('button[type="submit"]');
      if (!button) { return; }
      window.setTimeout(function () {
        button.disabled = true;
        button.textContent = "Sending…";
      }, 0);
    });
  }

  /* 3. TABLE PICKER */

  var bookingForm = document.querySelector(".reservation-form");

  if (bookingForm) {

    /* The stub on the left fills in as choices are made, so the booking is */
    var stub = {
      date:  document.querySelector('[data-stub="date"]'),
      time:  document.querySelector('[data-stub="time"]'),
      party: document.querySelector('[data-stub="party"]'),
      table: document.querySelector('[data-stub="table"]')
    };

    var updateStub = function () {
      var dateInput = document.getElementById("reservation_date");
      var timeInput = document.getElementById("reservation_time");
      var sizeInput = document.getElementById("party_size");

      if (stub.date && dateInput && dateInput.value) {
        var parts = dateInput.value.split("-");
        stub.date.textContent = new Date(parts[0], parts[1] - 1, parts[2])
          .toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
      }
      if (stub.time && timeInput) {
        stub.time.textContent = timeInput.options[timeInput.selectedIndex].text.trim();
      }
      if (stub.party && sizeInput) {
        stub.party.textContent = sizeInput.value ? sizeInput.value : "\u2014";
      }
      if (stub.table) {
        var picked = bookingForm.querySelector('input[name="table_id"]:checked');
        var label  = stub.table.querySelector(".stub__table-label");
        var area   = stub.table.querySelector(".stub__table-area");

        if (picked) {
          label.textContent = picked.dataset.label;
          area.textContent  = picked.dataset.area + " \u00b7 " + picked.dataset.seats + " seats";
          stub.table.classList.remove("stub__table--none");
        } else {
          label.textContent = "\u2014";
          area.textContent  = "No table chosen yet";
          stub.table.classList.add("stub__table--none");
        }
      }
    };

    bookingForm.addEventListener("change", updateStub);
    updateStub();

    bookingForm.querySelectorAll("[data-reload]").forEach(function (control) {
      control.addEventListener("change", function () {
        var check = document.createElement("input");
        check.type  = "hidden";
        check.name  = "action";
        check.value = "check";
        bookingForm.appendChild(check);
        bookingForm.submit();
      });
    });

  }

  /* 4. ORDER PAGE */

  var orderForm = document.querySelector(".order-form");

  if (orderForm) {

    /* (a) show the address box only for delivery */
    var addressField = orderForm.querySelector("[data-address-field]");

    if (addressField) {
      var syncAddress = function () {
        var delivery = orderForm.querySelector('[data-order-type][value="delivery"]');
        addressField.hidden = !(delivery && delivery.checked);
      };

      orderForm.querySelectorAll("[data-order-type]").forEach(function (radio) {
        radio.addEventListener("change", syncAddress);
      });

      syncAddress();
    }

    /* (b) turn each quantity box into a - [n] + stepper. */
    orderForm.querySelectorAll("[data-qty]").forEach(function (box) {
      var input = box.querySelector(".qty__input");
      if (!input) { return; }

      var max  = parseInt(input.getAttribute("max"), 10) || 20;
      var step = function (by) {
        var now = parseInt(input.value, 10) || 0;
        input.value = Math.min(max, Math.max(0, now + by));
        input.dispatchEvent(new Event("input", { bubbles: true }));
      };

      var minus = document.createElement("button");
      minus.type = "button";
      minus.className = "qty__btn";
      minus.textContent = "\u2212";                 /* a real minus sign */
      minus.setAttribute("aria-label", "One fewer " + (box.closest("[data-pick]") || {}).dataset?.name);
      minus.addEventListener("click", function () { step(-1); });

      var plus = document.createElement("button");
      plus.type = "button";
      plus.className = "qty__btn";
      plus.textContent = "+";
      plus.setAttribute("aria-label", "One more " + (box.closest("[data-pick]") || {}).dataset?.name);
      plus.addEventListener("click", function () { step(1); });

      box.insertBefore(minus, input);
      box.appendChild(plus);
      box.classList.add("qty--stepped");

      var syncButtons = function () {
        var now = parseInt(input.value, 10) || 0;
        minus.disabled = now <= 0;
        plus.disabled  = now >= max;
      };
      input.addEventListener("input", syncButtons);
      /* and once now, so a dish sitting at zero starts with its minus */
      syncButtons();
    });

    /* (c) keep the tray, the totals and the delivery note in step with the */
    var trayList  = orderForm.querySelector("[data-tray-list]");
    var trayEmpty = orderForm.querySelector("[data-tray-empty]");
    var trayCount = orderForm.querySelector("[data-tray-count]");
    var note      = orderForm.querySelector("[data-delivery-min]");

    /* The running total is printed twice — on the bar and inside the drawer — */
    var subBoxes   = orderForm.querySelectorAll("[data-order-subtotal]");
    var totalBoxes = orderForm.querySelectorAll("[data-order-total]");

    if (trayList && subBoxes.length && totalBoxes.length && note) {
      var minimum = parseInt(note.dataset.deliveryMin, 10) || 0;
      var fee     = parseInt(note.dataset.deliveryFee, 10) || 0;
      var money   = function (n) { return "KSh " + n.toLocaleString("en-KE"); };

      var refresh = function () {
        var subtotal = 0;
        var chosen   = 0;
        trayList.textContent = "";

        orderForm.querySelectorAll("[data-pick]").forEach(function (pick) {
          var input = pick.querySelector(".qty__input");
          var qty   = parseInt(input.value, 10) || 0;
          var price = parseFloat(pick.dataset.price) || 0;
          pick.classList.toggle("is-chosen", qty > 0);

          var badge = pick.querySelector("[data-card-badge]");
          if (badge) {
            badge.hidden = qty <= 0;
            badge.textContent = String(qty);
          }

          if (qty <= 0) { return; }

          chosen   += 1;
          subtotal += qty * price;

          var row = document.createElement("li");
          row.className = "cart__row";
          row.innerHTML =
            '<span class="cart__thumb cart__thumb--none" aria-hidden="true"></span>' +
            '<div class="cart__text">' +
              '<p class="cart__name"></p>' +
              '<p class="cart__desc"></p>' +
              '<span class="cart__step"></span>' +
            '</div>' +
            '<span class="cart__money"></span>';

          var photo = pick.dataset.photo;
          if (photo) {
            var img = document.createElement("img");
            img.className = "cart__thumb";
            img.src = photo;
            img.alt = "";
            img.width = 48; img.height = 48;
            row.replaceChild(img, row.firstChild);
          } else {
            row.firstChild.textContent = pick.dataset.name.trim().charAt(0).toUpperCase();
          }

          row.querySelector(".cart__name").textContent  = pick.dataset.name;
          row.querySelector(".cart__desc").textContent  = pick.dataset.desc || "";
          row.querySelector(".cart__money").textContent = money(qty * price);

          /* The real <input> lives on the dish card; these buttons drive it, */
          var step = row.querySelector(".cart__step");
          var nudge = function (by, label) {
            var b = document.createElement("button");
            b.type = "button";
            b.className = "cart__btn";
            b.textContent = by < 0 ? "\u2212" : "+";
            b.setAttribute("aria-label", label + " " + pick.dataset.name);
            b.addEventListener("click", function () {
              var now = parseInt(input.value, 10) || 0;
              input.value = Math.min(parseInt(input.getAttribute("max"), 10) || 20, Math.max(0, now + by));
              input.dispatchEvent(new Event("input", { bubbles: true }));
            });
            return b;
          };
          var count = document.createElement("span");
          count.className = "cart__count";
          count.textContent = String(qty);

          step.appendChild(nudge(-1, "One fewer"));
          step.appendChild(count);
          step.appendChild(nudge(1, "One more"));

          trayList.appendChild(row);
        });

        if (trayEmpty) { trayEmpty.hidden = chosen > 0; }
        if (trayCount) {
          trayCount.textContent = String(chosen);
          trayCount.dataset.empty = chosen > 0 ? "0" : "1";
        }

        var delivery = orderForm.querySelector('[data-order-type][value="delivery"]');
        var charging = subtotal > 0 && delivery && delivery.checked;

        orderForm.querySelectorAll("[data-fee-row]").forEach(function (el) {
          el.hidden = !charging;
        });

        var grand = subtotal + (charging ? fee : 0);
        subBoxes.forEach(function (el)   { el.textContent = money(subtotal); });
        totalBoxes.forEach(function (el) { el.textContent = money(grand); });

        var readyChip = orderForm.querySelector("[data-ready-chip]");
        var timeSel = document.getElementById("order_time");
        if (readyChip && timeSel) {
          readyChip.textContent = timeSel.options[timeSel.selectedIndex].text.replace(/ — too soon$/, "").trim();
        }

        var where = orderForm.querySelector("[data-where-summary]");
        var whereLabel = orderForm.querySelector("[data-where-label]");
        if (where && whereLabel) {
          var addressBox = document.getElementById("address");
          if (delivery && delivery.checked) {
            whereLabel.textContent = "Delivery address";
            where.textContent = (addressBox && addressBox.value.trim() !== "")
              ? addressBox.value.trim()
              : "Add your address below";
          } else {
            whereLabel.textContent = "Collection";
            where.textContent = where.dataset.pickup || where.textContent;
          }
        }

        if (charging && subtotal < minimum) {
          note.textContent = "Delivery starts at " + money(minimum) + " of food. You are "
                           + money(minimum - subtotal) + " short.";
          note.classList.add("order-note--warn");
        } else {
          note.textContent = "Delivery costs " + money(fee) + " on orders over "
                           + money(minimum) + ".";
          note.classList.remove("order-note--warn");
        }
      };

      orderForm.addEventListener("input", function (event) {
        if (event.target.classList.contains("qty__input")) { refresh(); }
      });
      orderForm.querySelectorAll("[data-order-type]").forEach(function (radio) {
        radio.addEventListener("change", refresh);
      });

      var timeField = document.getElementById("order_time");
      if (timeField) { timeField.addEventListener("change", refresh); }

      /* NOT addressField: that name is already taken above by the wrapping */
      var addressInput = document.getElementById("address");
      if (addressInput) { addressInput.addEventListener("input", refresh); }

      refresh();
    }
  }

  /* 5. FINDING A DISH            (order.php only) */

  var dishGrid = document.querySelector(".dishes");

  if (dishGrid) {
    var searchBox = document.querySelector("[data-dish-search]");
    var chips     = [].slice.call(document.querySelectorAll(".cat"));
    var cards     = [].slice.call(dishGrid.querySelectorAll("[data-pick]"));
    var noResults = document.querySelector("[data-no-results]");
    var resultNote = document.querySelector("[data-result-count]");
    var course    = "*";

    var applyFilter = function () {
      var term = (searchBox ? searchBox.value : "").trim().toLowerCase();
      var shown = 0;

      cards.forEach(function (card) {
        var matchesCourse = course === "*" || card.dataset.course === course;
        var matchesTerm   = term === "" || card.dataset.name.toLowerCase().indexOf(term) !== -1;
        var show = matchesCourse && matchesTerm;

        card.hidden = !show;
        if (show) { shown++; }
      });

      if (noResults)  { noResults.hidden = shown > 0; }
      if (resultNote) { resultNote.textContent = shown + (shown === 1 ? " dish" : " dishes"); }
    };

    if (searchBox) {
      searchBox.addEventListener("input", applyFilter);
    }

    chips.forEach(function (chip) {
      chip.addEventListener("click", function () {
        chips.forEach(function (c) { c.classList.remove("is-on"); });
        chip.classList.add("is-on");
        course = chip.dataset.filter;
        applyFilter();
      });
    });

    applyFilter();
  }

  /* 4. THE PAGE CHANGE */
  (function () {
    var FLAG  = "nav-morph";
    var WIDE  = "(min-width: 961px)";
    var CALM  = "(prefers-reduced-motion: reduce)";
    var LAND  = "index.php";

    var wide = function () { return window.matchMedia(WIDE).matches; };
    var calm = function () { return window.matchMedia(CALM).matches; };

    var root = document.documentElement;
    var body = document.body;

    /* ---- the arriving half ------------------------------------------------ */
    var arriving = /\bnav-(in|out|fade)\b/.exec(root.className);
    if (arriving) {
      window.setTimeout(function () {
        root.className = root.className.replace(/\s*\bnav-(in|out|fade)\b/g, "");
      }, 900);
    }

    /* ---- the leaving half ------------------------------------------------- */

    var onLanding = body.classList.contains("is-landing");
    var leaving = false;

    /* Which page does this href point at? Returns null for anything that is */
    var pageOf = function (link) {
      var raw = link.getAttribute("href");
      if (!raw || raw.charAt(0) === "#") { return null; }
      if (link.target && link.target !== "_self") { return null; }
      if (link.hasAttribute("download")) { return null; }

      var url;
      try { url = new URL(link.href, window.location.href); } catch (e) { return null; }
      if (url.protocol !== window.location.protocol) { return null; }  /* tel:, mailto: */
      if (url.host !== window.location.host) { return null; }          /* another site */

      var file = url.pathname.split("/").pop() || LAND;
      if (file.slice(-4) !== ".php") { return null; }

      var here = window.location.pathname.split("/").pop() || LAND;
      if (file === here) { return null; }   /* same page: let the anchor work */
      return file;
    };

    document.addEventListener("click", function (event) {
      if (leaving) { event.preventDefault(); return; }   /* one at a time */
      if (event.defaultPrevented || event.button !== 0) { return; }
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
      if (!wide() || calm()) { return; }

      var link = event.target.closest ? event.target.closest("a[href]") : null;
      if (!link) { return; }

      var target = pageOf(link);
      if (!target) { return; }

      /* which of the three movements is this? */
      var toLanding = target === LAND;
      var how = onLanding ? (toLanding ? "fade" : "in")
                          : (toLanding ? "out"  : "fade");

      event.preventDefault();
      leaving = true;
      try { window.sessionStorage.setItem(FLAG, how); } catch (e) {}
      body.classList.add("is-nav-leaving");

      /* follow when the cover has finished — or on a timer, so a dropped */
      var gone = false;
      var go = function () {
        if (gone) { return; }
        gone = true;
        window.location.href = link.href;
      };
      var cover = document.querySelector(".pagefade");
      if (cover) { cover.addEventListener("animationend", go, { once: true }); }
      window.setTimeout(go, how === "fade" ? 340 : 520);
    });

    /* Coming back with the Back button, a page may be restored from the cache */
    window.addEventListener("pageshow", function (event) {
      if (!event.persisted) { return; }
      leaving = false;
      body.classList.remove("is-nav-leaving");
      root.className = root.className.replace(/\s*\bnav-(in|out|fade)\b/g, "");
    });
  })();

  /* 5. "CONFIRM" -> THE FORM YOU FILL IN */
  (function () {
    document.querySelectorAll("[data-to-details]").forEach(function (step) {
      /* the form this button points at, taken from its own href rather than */
      var href   = step.getAttribute("href") || "";
      var target = href.charAt(0) === "#" ? document.getElementById(href.slice(1)) : null;
      if (!target) { return; }

      step.addEventListener("click", function (event) {
        event.preventDefault();

        var calm = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        target.scrollIntoView({ behavior: calm ? "auto" : "smooth", block: "start" });

        /* the first field still empty, so someone coming back is not sent to */
        var first  = null;
        var fields = target.querySelectorAll("input, select, textarea");
        for (var i = 0; i < fields.length; i++) {
          if (fields[i].type !== "hidden" && !fields[i].value) { first = fields[i]; break; }
        }
        if (!first && fields.length) { first = fields[0]; }

        target.classList.remove("is-noticed");
        void target.offsetWidth;          /* restart the highlight on a second click */
        target.classList.add("is-noticed");

        if (first) {
          /* after the scroll, or the browser yanks the page back */
          window.setTimeout(function () { first.focus({ preventScroll: true }); }, calm ? 0 : 420);
        }
      });
    });
  })();

  /* 6. THE TOP BAR TAKING A BACKGROUND ONCE THE PAGE MOVES */
  (function () {
    if (!document.body.classList.contains("is-landing")) { return; }

    var TRIGGER = 8;        /* px of scroll before the bar takes its colour */

    var update = function () {
      var y = window.scrollY || document.documentElement.scrollTop || 0;
      document.body.classList.toggle("is-scrolled", y > TRIGGER);
    };

    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update, { passive: true });
    update();           /* and once now, in case the page loads part-scrolled */
  })();

  /* 7. REPLAYING THE HERO WHEN THE PAGE COMES BACK FROM CACHE */
  (function () {
    if (!document.body.classList.contains("is-landing")) { return; }
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { return; }

    window.addEventListener("pageshow", function (event) {
      if (!event.persisted) { return; }      /* a normal load already ran it */
      document.body.classList.add("replay");
      void document.body.offsetWidth;        /* the reflow is what resets them */
      document.body.classList.remove("replay");
    });
  })();


  /* 8. SHOW / HIDE A PASSWORD */
  (function () {
    var toggles = document.querySelectorAll("[data-pw-toggle]");

    if (!toggles.length) { return; }

    Array.prototype.forEach.call(toggles, function (button) {
      var field = document.getElementById(button.getAttribute("data-pw-toggle"));

      if (!field) { return; }

      /* Only now is the control real. */
      button.hidden = false;

      var label = button.querySelector("[data-pw-label]") || button;

      var describe = function (shown) {
        /* aria-pressed carries the state for assistive software; the word */
        button.setAttribute("aria-pressed", shown ? "true" : "false");
        button.setAttribute("aria-label",
          (shown ? "Hide" : "Show") + " password");
        label.textContent = shown ? "Hide" : "Show";
      };

      describe(false);

      button.addEventListener("click", function () {
        var shown = field.type === "text";

        /* Where the cursor is, so revealing does not jump to the end of */
        var at = field.selectionStart;

        field.type = shown ? "password" : "text";
        describe(!shown);

        field.focus();

        try {
          if (at !== null) { field.setSelectionRange(at, at); }
        } catch (e) {
          /* Some engines refuse setSelectionRange on certain input types; */
        }
      });

      /* A revealed password must not survive being submitted or left on */
      if (field.form) {
        field.form.addEventListener("submit", function () {
          if (field.type === "text") {
            field.type = "password";
            describe(false);
          }
        });
      }
    });
  })();
  /* 9. THE STAFF SHORTCUT */

  /* Three clicks on the hero plate open the kitchen board. There is no
   * button for it anywhere, because customers have no reason to see one —
   * staff on the counter tablet tap the plate instead of typing a URL.
   *
   * Obscurity is not a lock. What actually protects the board is the staff
   * password in src/config.php; this only saves the typing. Turn the
   * password back on before the site is public. */
  (function () {
    /* The whole plate, not the photo inside it. .plate-art__ring is absolutely
     * positioned and 6% wider than the disc, so it paints on top and swallows
     * every click. Listening on the shared parent catches the ring, the disc
     * and the photo alike, whatever ends up stacked highest. */
    var plate = document.querySelector(".plate-art");
    if (!plate) {
      return;   /* not the home page */
    }

    var taps = 0;
    var timer = null;

    plate.addEventListener("click", function () {
      taps += 1;

      /* The run has to be deliberate: a stray click a second later starts
       * over rather than counting towards the three. */
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { taps = 0; }, 600);

      if (taps >= 3) {
        taps = 0;
        window.clearTimeout(timer);
        window.location.href = "kitchen.php";
      }
    });
  })();

})();
