  <!--**********************************
            Sidebar start
        ***********************************-->
        <div class="dlabnav">
			<div class="dlabnav-scroll">	
				<div class="copyright" style="
    text-align: center;
"> <?php if($_SESSION['user_type'] == 1 || $_SESSION['user_type'] == 2){ ?>	
    <button type="button" class="btn btn-primary center" data-bs-toggle="modal" data-bs-target="#exampleModal">
        <i class='fa fa-plus'></i> Create Events
    </button>
<?php } ?>

				</div>
				<ul class="metismenu" id="menu">
				    
				    
				    <?php if($_SESSION['user_type'] ==  0){ ?>	
	<li><a  href="/admin/profile" aria-expanded="false">
							<i class="material-symbols-outlined">home</i>
							<span class="nav-text">Dashboard</span>
						</a>
						

					</li>
<?php } else{?>

<?php } ?>


	    
				    <?php if( $_SESSION['user_type'] == 2){ ?>	
	<li><a  href="/admin/index" aria-expanded="false">
							<i class="material-symbols-outlined">home</i>
							<span class="nav-text">Dashboard</span>
						</a>
						

					</li>
					
						<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<i class="material-symbols-outlined">event</i>
						<span class="nav-text">Events</span>
					</a>
					<ul aria-expanded="false">
						<li><a href="/admin/events/">Event List</a></li>
						<li><a href="/admin/events/event-approval">Event Approval</a></li>

					</ul>

					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<i class="material-symbols-outlined">category</i>
						<span class="nav-text">Category</span>
					</a>
					<ul aria-expanded="false">
						<li><a href="/admin/category?action=add">Add Category</a></li>
						<li><a href="/admin/category">Category List</a></li>

					</ul>

					</li>
						<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<i class="material-symbols-outlined">place</i>
						<span class="nav-text">Event venues</span>
					</a>
					<ul aria-expanded="false">
						<li><a href="/admin/place?action=add">Add Vanue</a></li>
						<li><a href="/admin/place">Venue List</a></li>

					</ul>

					</li>
					
						<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<i class="material-symbols-outlined">play_circle</i>
						<span class="nav-text">Event Artist</span>
					</a>
					<ul aria-expanded="false">
						<li><a href="/admin/artist?action=add">Add Artist</a></li>
						<li><a href="/admin/artist">Artist List</a></li>

					</ul>

					</li>
					
					
						<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<i class="material-symbols-outlined">keyboard_double_arrow_up</i>
						<span class="nav-text">Event Promotion</span>
					</a>
					<ul aria-expanded="false">
					    
					    
					    <li><a href="/admin/promotion?action=add">Add Promotion Plan</a></li>
						<li><a href="/admin/promotion">Promotion Plan List</a></li>
					    					

					</ul>

					</li>



<li><a  href="/admin/users/" aria-expanded="false">
						<i class="material-symbols-outlined">verified_user</i>
						<span class="nav-text">Registered Users</span>
					</a>
				
					</li>
					


<li><a  href="/admin/partner/" aria-expanded="false">
						<i class="material-symbols-outlined">handshake</i>
						<span class="nav-text">Event Partners</span>
					</a>
				
					</li>
					
				
					
<?php } ?>



			  <?php if($_SESSION['user_type'] == 1 ){ ?>
			  
			  <li><a  href="/admin/index" aria-expanded="false">
							<i class="material-symbols-outlined">home</i>
							<span class="nav-text">Dashboard</span>
						</a>
						

					</li>
		<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<i class="material-symbols-outlined">event</i>
						<span class="nav-text">Events</span>
					</a>
					<ul aria-expanded="false">
						<li><a href="/admin/events/">Event List</a></li>

					</ul>

					</li>
					
					 <li><a  href="/admin/profile" aria-expanded="false">
							<i class="material-symbols-outlined">account_circle</i>
							<span class="nav-text">Profile</span>
						</a>
						

					</li>
<?php } ?>	

	  <li><a  href="/admin/logout" aria-expanded="false">
							<i class="material-symbols-outlined">logout</i>
							<span class="nav-text">Logout</span>
						</a>
						

					</li>
				
				</ul>
				

				<div class="copyright">
					<!--<p><strong>Highspinn Dashboard</strong></p>-->
					<p class="fs-12">Made with <span class="heart"></span> by Vinit Gupta</p>
				</div>
			</div>
        </div>
        <!--**********************************
            Sidebar end
        ***********************************-->
		