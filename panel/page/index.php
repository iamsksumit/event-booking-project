<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit(); 
}

if ($_SESSION['user_type'] == 0 || $_SESSION['user_type'] == 1 ) {
    header("Location: /profile");
    exit(); 
}

$pageTitle = "Page"; // Set the page title
?>

<?php include '../include/config.php'?>
<?php include '../include/header.php';?>
<?php include '../include/sidebar.php'?>

<?php
// Helper function to generate a slug from the page name
function generate_slug($name) {
    // Convert to lowercase, remove special characters, replace spaces with hyphens
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug); // Remove non-alphanumeric characters
    $slug = preg_replace('/-+/', '-', $slug); // Replace multiple hyphens with one
    return $slug;
}

// Add New Page
if (isset($_POST['save'])) {
    // Get form data
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $content = mysqli_real_escape_string($conn, $_POST['content']);
    
    // Slug generation (use form input or auto-generate)
    $slug = !empty($_POST['slug']) ? mysqli_real_escape_string($conn, $_POST['slug']) : generate_slug($name);
    
    // Insert query to add new page
    $sql = "INSERT INTO page (name, content, slug) 
            VALUES ('$name', '$content', '$slug')";

    if (mysqli_query($conn, $sql)) {
        // Success alert and redirect
        echo "<script>
            alert('Page added successfully!');
            window.location.href = 'index.php';
        </script>";
        exit();
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}

// Edit Page
if (isset($_POST['update'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $content = mysqli_real_escape_string($conn, $_POST['content']);
    $slug = mysqli_real_escape_string($conn, $_POST['slug']);
    $page_id = $_GET['id']; // Get the page id from the URL for editing

    // Update query to edit page details
    $sql = "UPDATE page 
            SET name = '$name', content = '$content', slug = '$slug' 
            WHERE id = '$page_id'";

    if (mysqli_query($conn, $sql)) {
        // Success alert and redirect
        echo "<script>
            alert('Page updated successfully!');
            window.location.href = 'index.php';
        </script>";
        exit();
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}

// Delete Page
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $page_id = $_GET['id'];
    $sql = "DELETE FROM page WHERE id = '$page_id'";
    if (mysqli_query($conn, $sql)) {
        // Success alert and redirect
        echo "<script>
            alert('Page deleted successfully!');
            window.location.href = 'index.php';
        </script>";
        exit();
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}
?>

<!--**********************************
    Content body start
***********************************-->
<div class="content-body">
    <div class="container-fluid">
        <!-- Add/Edit Form -->
        <?php if ($_GET['action'] == 'add' || $_GET['action'] == 'edit'): ?>
            <?php
            // Fetch page data if editing
            if ($_GET['action'] == 'edit' && isset($_GET['id'])) {
                $id = $_GET['id'];
                $sql = "SELECT * FROM page WHERE id = '$id'";
                $result = mysqli_query($conn, $sql);
                $page = mysqli_fetch_assoc($result);
            }
            ?>
            <div class="col-xl-12 col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"><?= ($_GET['action'] == 'add') ? 'Add New Page' : 'Edit Page' ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="basic-form">
                            <form role="form" class="form-validate form-horizontal" method="POST" action="" enctype="multipart/form-data">
                                <div class="mb-3 ">
                                    <label class="form-label">Page Name</label>
                                    <input type="text" class="form-control" name="name" value="<?= ($_GET['action'] == 'edit') ? $page['name'] : '' ?>" placeholder="Page Name" required>
                                </div>

                                <div class="mb-3 ">
                                    <label class="form-label">Slug</label>
                                    <input type="text" class="form-control" name="slug" value="<?= ($_GET['action'] == 'edit') ? $page['slug'] : '' ?>" placeholder="Page Slug">
                                    <!-- If slug is not provided, it will be auto-generated based on the page name -->
                                </div>

                                <div class="mb-3 ">
                                    <label class="form-label">Content</label>
                                    <textarea id="content" class="form-control" name="content"><?= ($_GET['action'] == 'edit') ? $page['content'] : '' ?></textarea>
                                </div>

                                <div class="mb-3 row">
                                    <div class="col-sm-10">
                                        <button type="submit" class="btn btn-primary" name="<?= ($_GET['action'] == 'add') ? 'save' : 'update' ?>">
                                            <?= ($_GET['action'] == 'add') ? 'Add New Page' : 'Update Page' ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Table for listing pages -->
        <div class="table-responsive">
            <table class="table table-responsive-md">
                <thead>
                    <tr>
                        <th><strong>ID</strong></th>
                        <th><strong>Name</strong></th>
                        <th><strong>Slug</strong></th>
                        <th><strong>Actions</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Fetch pages to display
                    $sql = "SELECT * FROM page";
                    $result = mysqli_query($conn, $sql);
                    while ($page = mysqli_fetch_assoc($result)):
                    ?>
                        <tr>
                            <td><strong><?= $page['id'] ?></strong></td>
                            <td><?= $page['name'] ?></td>
                            <td><?= $page['slug'] ?></td>
                            <td>
                                <div class="d-flex">
                                    <a href="?action=edit&id=<?= $page['id'] ?>" class="btn btn-primary shadow btn-xs sharp me-1">
                                        <i class="fa fa-pencil"></i>
                                    </a>
                                    <a href="?action=delete&id=<?= $page['id'] ?>" class="btn btn-danger shadow btn-xs sharp">
                                        <i class="fa fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.0/classic/ckeditor.js"></script>
<script>
    ClassicEditor
        .create(document.querySelector('#content'))
        .catch(error => {
            console.error(error);
        });
</script>

<?php include '../include/footer.php'; ?>
