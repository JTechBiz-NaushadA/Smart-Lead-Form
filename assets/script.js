document.addEventListener("DOMContentLoaded", function () {

let formEl = document.getElementById("txForm");
let chips = document.querySelectorAll(".chips span");
let hidden = document.getElementById("interest");
let msgBox = document.querySelector(".tx-message");
let btn = formEl.querySelector("button");

/* Chips */
chips.forEach(c => {
    c.onclick = () => {
        c.classList.toggle("active");
        update();
    }
});

function update() {
    let vals = [];
    document.querySelectorAll(".chips .active").forEach(el => {
        vals.push(el.dataset.value);
    });
    hidden.value = vals.join(',');
}

/* AJAX Submit */
formEl.onsubmit = function (e) {
    e.preventDefault();

    let form = new FormData(this);
    form.append('action', 'tx_submit');
    form.append('nonce', tx_ajax.nonce);

    /* Loading state */
    btn.disabled = true;
    btn.classList.add("loading");
    btn.innerHTML = "Processing...";

    fetch(tx_ajax.url, {
        method: 'POST',
        body: form
    })
    .then(res => res.json())
    .then(res => {

        showMessage(res.data, "success");

        /* Reset form */
        formEl.reset();
        hidden.value = "";

        document.querySelectorAll(".chips span").forEach(c => c.classList.remove("active"));

    })
    .catch(() => {
        showMessage("Something went wrong. Try again.", "error");
    })
    .finally(() => {
        btn.disabled = false;
        btn.classList.remove("loading");
        btn.innerHTML = "Download Now!";
    });
};

/* Message UI */
function showMessage(text, type) {

    msgBox.innerText = text;
    msgBox.className = "tx-message " + type;
    msgBox.style.display = "block";

    setTimeout(() => {
        msgBox.style.opacity = "0";
    }, 4000);

    setTimeout(() => {
        msgBox.style.display = "none";
        msgBox.style.opacity = "1";
    }, 5000);
}

});