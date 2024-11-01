(function ($) {
  $(document).ready(function () {
    $(".shiplee-additional-fields input[name=perishable]").on(
      "click",
      function () {
        $perisableMaxAttemptsRow = $(
          ".shiplee-additional-fields select[name=perishable_max_attempts]"
        ).closest("tr");

        if ($(this).prop("checked")) {
          $perisableMaxAttemptsRow.show();
        } else {
          $perisableMaxAttemptsRow.hide();
        }
      }
    );

    $(".shiplee-create-label").on("click", function (e) {
      var wrapper = $(this.parentNode),
        form = $("#shiplee-create-label-form");

      form.find(".button-primary").attr("disabled", true);

      if (wrapper.hasClass("open")) {
        wrapper.removeClass("open");
        form.hide();
      } else {
        $(".shiplee-wrapper.open .shiplee-create-label").click();

        var title = form.find(".shiplee-create-label-title"),
          tomorrow = new Date(Date.now() + 86400000),
          id = wrapper.data("id"),
          fields = wrapper.data("fields"),
          tbodyRates = form.find(".shiplee-rates"),
          tbodyFields = form.find(".shiplee-additional-fields"),
          trPrototype = tbodyRates.data("prototype");

        tbodyRates.addClass("spinner").html("");
        tbodyFields.hide();

        fields.action = "get_product_availability";
        $.post(ajaxurl, fields, function (response) {
          if (response.status == "success") {
            tbodyRates.removeClass("spinner");

            $(response.rates).each(function (k, v) {
              var tr = $(trPrototype).clone(),
                td = tr.find("td");

              tbodyRates.append(tr);

              tr.data("rate", v);
              tr.find("h4").html(v.description);

              if (v.available_options && v.available_options.length) {
                $(v.available_options).each(function (i, o) {
                  td.append(" ").append(
                    $("<span />").html(
                      o.description + " (" + o.token_amount + " tokens)"
                    )
                  );
                });
              } else {
                tr.find("label, span").remove();
              }

              tr.on("click", function (e) {
                $(this).siblings(".selected").removeClass("selected");
                $(this).addClass("selected");

                $("#shiplee-create-label-form .button-primary").removeAttr(
                  "disabled"
                );

                var rate = $(this).data("rate"),
                  deliverDate = tbodyFields.find('[name="delivery_date"]'),
                  minDate = "+" + (rate.same_day ? 0 : 1) + "d";

                tbodyFields.find("tr").hide();

                if (rate.carrier === "fedex") {
                  tbodyFields.find('[name="weight"]').closest("tr").show();
                  tbodyFields
                    .find('[name="value_of_goods"]')
                    .closest("tr")
                    .show();
                  tbodyFields
                    .find('[name="description_of_goods"]')
                    .closest("tr")
                    .show();
                } else {
                  // rate.carrier == 'rjp'

                  var k,
                    keys = rate.available_options.map((option) =>
                      option.name.replace("option_", "")
                    );

                  for (k in keys) {
                    tbodyFields
                      .find('[name="' + keys[k] + '"]')
                      .closest("tr")
                      .show();
                  }

                  tbodyFields.find(".deliverer_note").show();
                }

                deliverDate.datepicker("option", "minDate", minDate);
                deliverDate.datepicker("option", "maxDate", "+2w");

                deliverDate.datepicker("setDate", minDate);
                deliverDate.closest("tr").show();

                tbodyFields.show();
              });
            });
          } else if (response.message) {
            alert(`Shiplee WooCommerce Plug-in: ${response.message}`);
          }
        });

        wrapper.addClass("open");
        form.data("id", id);
        form.find('[name="age_check_18"]:checked').click();
        form.find('[name="require_signature"]:checked').click();
        form.find('[name="dont_allow_neighbours"]:checked').click();
        form.find('[name="perishable"]:checked').click();
        form.find('[name="perishable_max_attempts"]').val(2);
        form.find('[name="deliverer_note"]').val("");
        form.find('[name="weight"]').val("");
        form.find('[name="value_of_goods"]').val("");
        form.find('[name="description_of_goods"]').val("");

        title.html(title.data("content").replace("%d", id));
        form.css({
          top: wrapper.offset().top - 32 + wrapper.height() + "px",
          left: wrapper.offset().left + "px",
        });
        form.show();
      }
    });

    $("#shiplee-create-label-form .button-primary").on("click", function (e) {
      var form = $("#shiplee-create-label-form"),
        selectedRate = form.find(".selected");

      if (selectedRate.length == 0) {
        return;
      }

      var rate = selectedRate.data("rate"),
        data = {
          action: "create_label",
          id: form.data("id"),
          product_id: rate.id,
          carrier: rate.carrier,
          weight: form.find('[name="weight"]').val(),
          value_of_goods: form.find('[name="value_of_goods"]').val(),
          description_of_goods: form
            .find('[name="description_of_goods"]')
            .val(),
          age_check_18: form.find('[name="age_check_18"]:checked').length,
          require_signature: form.find('[name="require_signature"]:checked')
            .length,
          dont_allow_neighbours: form.find(
            '[name="dont_allow_neighbours"]:checked'
          ).length,
          perishable: form.find('[name="perishable"]:checked').length,
          delivery_date: form.find('[name="delivery_date"]').val(),
          deliverer_note: form.find('[name="deliverer_note"]').val(),
        };

      if (data["perishable"]) {
        data["perishable_max_attempts"] = form
          .find('[name="perishable_max_attempts"]')
          .val();
      }

      $.post(ajaxurl, data, function (response) {
        if (response.status == "success") {
          $(".shiplee-wrapper.open .shiplee-create-label").click();
          $('.shiplee-label-wrapper[data-id="' + data.id + '"]').append(
            form.data("created-message")
          );
        } else if (response.message) {
          alert(`Shiplee WooCommerce Plug-in: ${response.message}`);
        }
      });
    });
  });
})(jQuery);
