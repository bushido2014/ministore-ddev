(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.productsSlider = {
    attach(context) {
      once('productsSlider', '.products-slider', context).forEach(function (el) {
        // Mută wrapper-ul direct sub .swiper
        const wrapper = el.querySelector('.swiper-wrapper');
        if (wrapper && wrapper.parentElement !== el) {
          el.insertBefore(wrapper, el.firstChild);
        }

        const slides = el.querySelectorAll('.swiper-slide');
        if (slides.length === 0) return;

        new Swiper(el, {
          loop: slides.length >= 6,
          slidesPerView: 3,
          spaceBetween: 20,
          autoplay: {
    delay: 3200,
  },
          // navigation: {
          //   nextEl: el.querySelector('.swiper-button-next'),
          //   prevEl: el.querySelector('.swiper-button-prev'),
          // },
  //         pagination: {
  //   el: '.swiper-pagination',
  //   type: 'bullets',
  // },
          breakpoints: {
            0:    { slidesPerView: 1, loop: false },
            640:  { slidesPerView: 2, loop: false },
            1024: { slidesPerView: 3, loop: slides.length >= 6 },
          },
        });
      });
    }
  };
})(Drupal, once);