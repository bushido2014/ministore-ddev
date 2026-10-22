(function (Drupal, once) {
    "use strict";

    Drupal.behaviors.gadgetSlider = {
        attach(context) {
            once('gadgetSlider', '.gadget-slide', context).forEach(function (el) {
                new Swiper(el, {
                    loop: false,
    rewind: true,  // ← in loc de loop, foloseste rewind
    spaceBetween: 30,
                   
                    slidesPerView: 3,
                    autoplay: {
                        delay: 3200,
                        disableOnInteraction: false,
                    },
                    breakpoints: {
                        0:    { slidesPerView: 1 },
                        640:  { slidesPerView: 2 },
                        1024: { slidesPerView: 3 },
                    },
                });
            });
        }
    };

})(Drupal, once);