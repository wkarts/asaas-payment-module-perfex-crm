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
                  <td>id </td>
                  <td>dateCreated </td>
                  <td>customer </td>
                  <td>value </td>
                  <td>netValue </td>
                  <td>description </td>
                  <td>billingType </td>
                  <td>canBePaidAfterDueDate </td>
                  <td>status </td>
                  <td>dueDate </td>
                  <td>originalDueDate </td>
                  <td>clientPaymentDate </td>
                  <td>installmentNumber </td>
                  <td>invoiceUrl </td>
                  <td>invoiceNumber </td>
                  <td>externalReference </td>
                </tr>
              </thead>
              <tbody>
                <?php	 foreach($new_array as $new_row) {  ?>
                <tr>
                  <td><?php echo  $new_row["id"];    ?></td>
                  <td><?php echo  $new_row["dateCreated"];   ?></td>
                  <td><?php echo  $new_row["customer"];      ?></td>
                  <td><?php echo  $new_row ["value"]; ?></td>
                  <td><?php echo  $new_row["netValue"];    ?></td>
                  <td><?php echo   $new_row["description"];  ?></td>
                  <td><?php echo  $new_row["billingType"]; ?></td>
                  <td><?php echo  $new_row["canBePaidAfterDueDate"];    ?></td>
                  <td><?php echo  $new_row["status"]; ?></td>
                  <td><?php echo  $new_row["dueDate"]; ?></td>
                  <td><?php echo  $new_row["originalDueDate"];     ?></td>
                  <td><?php echo  $new_row["clientPaymentDate"];   ?></td>
                  <td><?php echo  $new_row["installmentNumber"];   ?></td>
                  <td><?php echo  $new_row ["invoiceUrl"];     ?></td>
                  <td><?php echo  $new_row["invoiceNumber"];  ?></td>
                  <td><?php echo  $new_row["externalReference"]; ?></td>
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
