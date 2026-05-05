jQuery(function($){

    // on upload button click
    $('body').on( 'click', '.misha-upl', function(e){

        e.preventDefault();

        var button = $(this),
            custom_uploader = wp.media({
                title: 'Insert image',
                library : {
                    // uploadedTo : wp.media.view.settings.post.id, // attach to the current post?
                    type : 'image'
                },
                button: {
                    text: 'Use this image' // button label text
                },
                multiple: false
            }).on('select', function() { // it also has "open" and "close" events
                var attachment = custom_uploader.state().get('selection').first().toJSON();
                button.html('<img src="' + attachment.url + '"  style="max-width: 80px;">').next().val(attachment.id).next().show();
                $(".misha-img").val(attachment.id);
                $(".misha-rmv").show();
            }).open();

    });

    // on remove button click
    $('body').on('click', '.misha-rmv', function(e){

        e.preventDefault();

        var button = $(this);
        button.next().val(''); // emptying the hidden field
        button.hide().prev().html('<span>Upload image</span>');
        $(".misha-rmv").hide();
    });

});