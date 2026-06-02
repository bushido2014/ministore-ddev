// 
(function (Drupal, once) {
    "use strict";

    Drupal.behaviors.homeSlider = {
        attach(context) {
            once('homeSlider', '.home-slider', context).forEach(function (el) {
                new Swiper(el, {
                    loop: true,
                    spaceBetween: 30,
                    slidesPerView: 1,
                });
            });
        }
    };

})(Drupal, once);