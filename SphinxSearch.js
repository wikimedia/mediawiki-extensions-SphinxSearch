function sphinxShowCats(input) {

    var parent_id = input.id.substring(0, input.id.indexOf('_'));
    var delta = (input.checked ? -1 : 1);
    var parent_fld = input.form['catp[_' + parent_id + ']'];
    var seen_parents = [];
    seen_parents[parent_id] = true;
    var cnt = input.form.elements.length;

    while (parent_fld) {
      var parent_cnt = parseInt(parent_fld.value);
      if (isNaN(parent_cnt)) {
        parent_cnt = 0;
      }
      parent_fld.value = parent_cnt + delta;
      parent_fld = null;
      for (var i = 0; i < cnt; i++) {
        var el = input.form.elements[i];
        if (el.name.indexOf('catp') == 0) {
          var grandparent_id = el.name.replace(/[^\d]+/g, '');
          if (seen_parents[grandparent_id]) {
            continue;
          }
          if (document.getElementById(grandparent_id + '_' + parent_id)) {
            parent_fld = input.form['catp[_' + grandparent_id + ']'];
            seen_parents[grandparent_id] = true;
          }
        }
      }
    }

    if (input.checked) {
      return;
    }

    var div = document.getElementById('cat' + input.value + '_children');
    if (div.innerHTML.length > 10) {
      return;
    }

	injectSpinner( input, 'sphinxsearch' );

    function f( request ) {
        var result = request.responseText;

        if (request.status != 200) {
            result = "<div class='error'> " + request.status + " " + request.statusText + ": " + result + "</div>";
        }
		removeSpinner( 'sphinxsearch' );
        div.innerHTML = result;
    }

    sajax_do_call( "SphinxSearch::ajaxGetCategoryChildren", [input.value] , f );
}