// 
(function (Drupal, once) {
    "use strict";

    Drupal.behaviors.gadgetSlider = {
        attach(context) {
            once('gadgetSlider', '.gadget-slide', context).forEach(function (el) {
                new Swiper(el, {
                    loop: true,
                    spaceBetween: 30,
                    slidesPerView: 1,
                });
            });
        }
    };

})(Drupal, once);