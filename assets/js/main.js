$(function(){
    var $body = $('body');

    $body.find('select').selectpicker({
        noneSelectedText: 'None selected',
        size: 4
    });

    $body.on('click', 'a[href^="#"]', function (event) {
        event.preventDefault();

        $('html, body').animate({
            scrollTop: $($.attr(this, 'href')).offset().top - 60
        }, 500);
    });

    setTimeout(function(){
        $('.alert-wrapper > .alert').slideUp(500);
    }, 7500);
});
