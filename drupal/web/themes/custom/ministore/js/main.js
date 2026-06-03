/**
 * @file
 * dr-agency theme JavaScript.
 */
(function (Drupal, once) {
  "use strict";
  
  Drupal.behaviors.ministore = {
    attach: function (context, settings) {
      console.log("🎨 ministore theme loaded!");


        
      // Scroll top button
      let scrollTop = document.querySelector('.scroll-top');
      if (scrollTop) {
        // Click handler
        scrollTop.addEventListener('click', (e) => {
          e.preventDefault();
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
        // Scroll visibility
        function toggleScrollTop() {
          if (window.scrollY > 100) {
            scrollTop.classList.add('active');
          } else {
            scrollTop.classList.remove('active');
          }
        }
        
        window.addEventListener('scroll', toggleScrollTop);
        toggleScrollTop(); // Initial check
      }
    }
  };
})(Drupal, once);