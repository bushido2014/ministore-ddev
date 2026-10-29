(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.mmenu = {
    attach(context) {
      once('mmenu', '#mobile-menu', context).forEach(function (el) {
        const menu = new MmenuLight(el, "(max-width: 820px)");
        menu.navigation();

        const drawer = menu.offcanvas({
          position: "right"
        });

        const hamburger = document.querySelector('.hamburger-btn');

        if (hamburger) {
          hamburger.addEventListener('click', function (e) {
            e.preventDefault();
            drawer.open();
          });
        }
      });
    }
  };
})(Drupal, once);