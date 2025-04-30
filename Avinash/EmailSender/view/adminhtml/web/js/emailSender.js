/**
 * Saurav Kumar
 *
 * @category   Saurav
 * @package    Avinash_EmailSender
 * @author     Saurav Kumar
 */
define([
    'jquery',
    'mage/mage',
    'mage/translate',
    'Magento_Ui/js/modal/modal',
], function ($, mage, $t, modal) {
    $.widget('mage.emailSender', {
        options: {
            successMsg: $.mage.__('Email sent successfully.'),
            errorMsg: $.mage.__('Email not sent.')
        },
        /**
         * Widget initialization
         * @private
         */
        _create: function () {
            self = this;
            $('#email-form-submit').on('click', function (event) {
                event.preventDefault(); // Prevent default form submission
                var dataForm = $('#email-form');
                if (dataForm.valid()) {
                    var formData = dataForm.serialize(); // Serialize form data
                    $("body").trigger("processStart");
                    $.ajax({
                        url: self.options.send_url, // The URL from getFormActionUrl() method
                        type: 'POST',
                        data: formData,
                        dataType: 'json',
                        success: function (response) {
                            $(".success-msg").remove();
                            $("#messages").hide();
                            $("body").trigger("processStop");
                            $(".total-count").html($t("Total Count") + " : " + response.totalCount);
                            $(".remain-count").html($t("Remaining Count") + " : " + response.remainingCount);
                            $(".message.message-success.success").append("<div class='success-msg'>"+response.message+"</div>");
                            $("#messages").show();
                        },
                        error: function (error) {
                            $("body").trigger("processStop");
                            alert("An error occurred while submitting the form.");
                        }
                    });
                }
            }); 
            self.previewEmailTeplate();
        },

        previewEmailTeplate: function () {
            var options = {
                type: 'popup',
                responsive: true,
                innerScroll: true,
                buttons: [{
                    text: $.mage.__('Ok'),
                    class: '',
                    click: function () {
                        this.closeModal();
                    }
                }]
            };

            var popup = modal(options, $('#preview-template'));
            $("#preview-template").on('click', function () {
                $("#preview-template").modal("openModal");
            });

            $('#preview-temp').on('click', function () {
                $('#preview-template').html("");
                let htmlData = $("#message").val();
                $('#preview-template').html(htmlData);
                $('#preview-template').click();
            });
        }
    });

    return $.mage.emailSender;
});