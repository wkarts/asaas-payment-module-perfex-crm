<?php init_head(); ?>

<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <table class="table dt-table">
              <thead>
                <tr>
                  <td>id</td>
                  <td>name</td>
                  <td>cpfCnpj</td>
                  <td>dateCreated</td>
                </tr>
              </thead>
              <tbody>
                <?php	 foreach($response as $row) {  ?>
                <tr>
                  <td><?php echo  $row["id"];    ?></td>
                  <td><?php echo  $row["name"];   ?></td>
                  <td><?php echo  $row["cpfCnpj"];   ?></td>
                  <td><?php echo  $row["dateCreated"];   ?></td>
                </tr>
                <?php	 }	?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
