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
                  <td>dateCreated</td>
                  <td>customer</td>
                  <td>value</td>
                  <td>netValue</td>
                  <td>description</td>
                  <td>billingType</td>
                  <td>status</td>
                  <td>dueDate</td>
                  <td>originalDueDate</td>
                  <td>paymentDate</td>
                  <td>clientPaymentDate</td>
                  <td>invoiceNumber</td>
                  <td>externalReference</td>
                </tr>
              </thead>
              <tbody>
                <?php	 foreach($response as $row) {  ?>
                <tr>
                  <td><?php echo  $row["id"];    ?></td>
                  <td><?php echo  $row["dateCreated"];   ?></td>
                  <td><?php echo  $row["customer"];      ?></td>
                  <td><?php echo  $row ["value"]; ?></td>
                  <td><?php echo  $row["netValue"];    ?></td>
                  <td><?php echo   $row["description"];  ?></td>
                  <td><?php echo  $row["billingType"]; ?></td>
                  <td><?php echo  $row["status"]; ?></td>
                  <td><?php echo  $row["dueDate"]; ?></td>
                  <td><?php echo  $row["originalDueDate"];     ?></td>
                  <td><?php echo  $row["paymentDate"];  ?></td>
                  <td><?php echo  $row["clientPaymentDate"];   ?></td>
                  <td><?php echo  $row["invoiceNumber"];  ?></td>
                  <td><?php echo  $row["externalReference"]; ?></td>
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
