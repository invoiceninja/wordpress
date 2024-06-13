function in_update_cart(product_id, quantity) {
    document.getElementById("cart_action").value = "update";
    document.getElementById("quantity").value = quantity;
    document.getElementById("product_id").value = product_id;
    document.getElementById("invoiceninja_cart").submit();
}
