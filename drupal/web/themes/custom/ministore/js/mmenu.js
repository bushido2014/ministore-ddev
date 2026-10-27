(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.mmenu = {
    attach(context) {
      once('mmenu', '#mobile-menu', context).forEach(function (el) {
        const menu = new MmenuLight(el, "(max-width: 768px)");
        const navigator = menu.navigation();
        const drawer = menu.offcanvas({
          position: "right"  // 
        });

        document.querySelector('.hamburger-btn')
          .addEventListener('click', (e) => {
            e.preventDefault();
            drawer.open();
          });
      });
    }
  };

})(Drupal, once);