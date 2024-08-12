<?php
//echo filter_input(INPUT_SERVER, 'PHP_SELF');
if (!empty($_FILES)) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    require './inc/includes.inc.php';
    $request = new CheckRequests(
            values: filter_input_array(INPUT_POST, [
                    'version' => FILTER_VALIDATE_FLOAT,
        ]),
        required: ['version'],
        filesRequired: ['xml']
    );
    if ($request->ok()) {
        $xiopd = new XIOPD(version: $request->val('version'), xml: $request->getFile('xml')['tmp_name']);
        try {
            $validated = $xiopd->validateSchema();
        } catch (DOMException $e) {
            $validated = false;
        }
        echo <<<MODAL
	<div class="modal" id="modal_result" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl ">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">Validator result</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
MODAL;
        if ($validated) {
            echo <<<SUCCESS
	    <div class="alert alert-success" role="alert">
		<i class="fas fa-check fa-fw"></i> Structure was successfully validated
	    </div>
SUCCESS;
            try {
                $xiopd->validateLogic();
            } catch (Exception $e) {
            }
        } else {
            echo($xiopd->displayErrorsAsTable());
        }
        echo <<<MODAL
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
MODAL;

    }
    else{
        print_r($request->getErrors());
    }
}
?>

<!doctype html>
<html lang="en" class="h-100" data-bs-theme="dark">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/vendor/components/font-awesome/css/all.min.css"/>
    <!-- Bootstrap CSS -->
    <link href="/vendor/twbs/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>XI:OPD Validator</title>
</head>
<body class="h-100">

<main class="h-100 d-flex align-items-center justify-content-center">
    <section class="shadow p-5 text-bg-light">
        <h1>XI:OPD Validator</h1>
        <p>
            This is a simple xi:opd validator that checks<br>
            an XML tree for validity based on the xsd file provided.
        </p>
        <form method="POST" action="<?= filter_input(INPUT_SERVER, 'PHP_SELF') ?>" enctype="multipart/form-data">
            <div class="form-floating mb-3">
                <select required name="version" class="form-select" id="floatingSelectVersion"
                        aria-label="Floating label select example">
                    <option value="1">1</option>
                    <option value="1.10" selected>1.1</option>
                </select>
                <label for="floatingSelectVersion">selects the version to validate</label>
            </div>
            <div class="mb-3">
                <label for="formFile" class="form-label">Select file to validate</label>
                <input class="form-control" type="file" id="formFile" required accept="text/xml" name="xml">
            </div>
            <button type="submit" class="btn btn-primary mb-3">Check XML file</button>
            <div class="d-flex flex-column">
                <a href="inc/xi-opd_V1.10_Handbuch.pdf" download>Manual (german) V1.1</a>
                <a href="inc/xi-opd_V1_10.xsd" download>download xsd Scheme V1.1</a>
                <hr>
                <a href="inc/Handbuch-xi-opd_V1.0.pdf" download>Manual (german) V1</a>
                <a href="inc/Exchange_Interface_Open_ProjectData_102.xsd" download>download xsd Scheme V1</a>
            </div>

        </form>
    </section>
</main>


<script src="/vendor/components/jquery/jquery.min.js"></script>
<script src="/vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript">
    $(document).ready(function () {
        const modalResult = $('#modal_result');
        if (modalResult.length) {
            modalResult.modal('show');
        }
    });
</script>
</body>
</html>
