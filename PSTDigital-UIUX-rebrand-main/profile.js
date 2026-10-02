document.getElementById("profileForm").addEventListener("submit", function (e) {
    e.preventDefault();

    const fields = [
        "nama",
        "tgl_lahir",
        "pendidikan",
        "pekerjaan",
        "provinsi",
        "kabupaten",
        "email",
        "hp"
    ];

    const alertBox = document.getElementById("alertProfile");
    const navbarHeight = document.querySelector(".navbar").offsetHeight;

    const isEmpty = fields.some(id => {
        const el = document.getElementById(id);
        return !el || el.value.trim() === "";
    });

    if (isEmpty) {
        alertBox.classList.remove("d-none");

        // posisi alert (dikurangi tinggi navbar)
        const alertPosition =
            alertBox.getBoundingClientRect().top +
            window.pageYOffset -
            navbarHeight -
            10;

        window.scrollTo({
            top: alertPosition,
            behavior: "smooth"
        });

    } else {
        alertBox.classList.add("d-none");
        alert("Profil berhasil disimpan");
    }
});
