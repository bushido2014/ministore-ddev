// 
(function (Drupal, once) {
    "use strict";

    Drupal.behaviors.testimonialsSlider = {
        attach(context) {
            once('testimonialsSlider', '.testimonials-slider', context).forEach(function (el) {
                new Swiper(el, {
                    loop: true,
                    spaceBetween: 30,
                    slidesPerView: 1,
                });
            });
        }
    };

})(Drupal, once);