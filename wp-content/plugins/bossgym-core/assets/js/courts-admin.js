(function ($) {
  'use strict';
  $(function () {
    let frame;
    $(document).on('click', '.bgc-select-image', function (event) {
      event.preventDefault();
      const field = $(this).closest('.bgc-image-field');
      if (frame) { frame.open(); return; }
      frame = wp.media({ title: 'Saha fotoğrafı seç', button: { text: 'Bu görseli kullan' }, multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        const image = frame.state().get('selection').first().toJSON();
        field.find('input[name="bgc_image_id"]').val(image.id);
        field.find('.bgc-image-preview').html($('<img>', { src: image.url, alt: '' }));
      });
      frame.open();
    });
    $(document).on('click', '.bgc-remove-image', function (event) {
      event.preventDefault();
      const field = $(this).closest('.bgc-image-field');
      field.find('input[name="bgc_image_id"]').val('0');
      field.find('.bgc-image-preview').text('Varsayılan temsilî görsel kullanılacak.');
    });
  });
}(jQuery));
