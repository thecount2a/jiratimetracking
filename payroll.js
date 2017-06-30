function pad(n, width, z) {
  z = z || '0';
  n = n + '';
  return n.length >= width ? n : new Array(width - n.length + 1).join(z) + n;
}

var app = angular.module("payrollApp", ['ngTouch', 'ui.grid', 'ui.grid.edit', 'ui.grid.selection', 'ngCookies']);
app.controller("payrollController", ["$scope", "$http", "$cookies", function($scope, $http, $cookies) {
    $scope.users = [];
    $scope.timespans = [];
    $scope.blob = null;
    $scope.blob_loaded = false;
    $scope.blob_nonce = null;
    $scope.payroll_data = {};
    $scope.payroll_key = null;
    $scope.payroll_decrypted = false;

    $scope.hideGrid = true;
    $scope.currentEdit = null;
    $scope.gridOptions = {};
    $scope.gridOptions.multiSelect = false;

    $scope.gridOptions.onRegisterApi = function(gridApi) {
          //set gridApi on scope
        $scope.gridApi = gridApi;
    };

    $http.get("payroll.php?blob=true")
	.success(function(data) {
		$scope.blob = data;
		$scope.blob_loaded = true;
    })
	.error(function(data, status) {
		alert("Failed to load payroll data: " + data);
    });
    $scope.currenttimespan = $scope.timespans[0];
    $scope.savePayroll = function () {
	var key = sha256.pbkdf2(nacl.util.decodeUTF8($scope.payroll_key), nacl.util.decodeUTF8($cookies.get("timetracking_user")), 10000, 32);
	// Only save and generate new nonce if data changes
	if (nacl.util.encodeBase64($scope.blob_nonce) + ':' + nacl.util.encodeBase64(nacl.secretbox(nacl.util.decodeUTF8(JSON.stringify($scope.payroll_data)), $scope.blob_nonce, key)) != $scope.blob)
	{
		var nonce = nacl.randomBytes(24);
		var box = nacl.secretbox(nacl.util.decodeUTF8(JSON.stringify($scope.payroll_data)), nonce, key); 
		$http.post("payroll.php?blob=true", nacl.util.encodeBase64(nonce)+':'+nacl.util.encodeBase64(box))
			.success(function(data) {
		})
			.error(function(data, status) {
				alert("Failed to save payroll data: " + data);
		});
	}
    };
    $scope.decryptBlob = function () {
	if ($scope.blob_loaded)
	{
		if ($scope.payroll_key.length < 6)
		{
			alert("Password too short, it must be at least 6 characters long");
		}
		else
		{
			var success = false;
			if ($scope.blob.length > 0)
			{
				var key = sha256.pbkdf2(nacl.util.decodeUTF8($scope.payroll_key), nacl.util.decodeUTF8($cookies.get("timetracking_user")), 10000, 32);
				var parts = $scope.blob.split(':');
				$scope.blob_nonce = nacl.util.decodeBase64(parts[0]);
				var decrypted = nacl.secretbox.open(nacl.util.decodeBase64(parts[1]), $scope.blob_nonce, key);
				if (!decrypted)
				{
					alert("Wrong password");
				}
				else
				{
					$scope.payroll_data = JSON.parse(nacl.util.encodeUTF8(decrypted));
					$scope.payroll_decrypted = true;
					success = true;
				}
			}
			else
			{
				$scope.payroll_decrypted = true;
				success = true;
			}
			if (success)
			{
				if ($scope.payroll_data.timespans)
				{
					for (var i = 0; i < $scope.payroll_data.timespans.length; i++)
					{
						$scope.timespans.push($scope.payroll_data.timespans[i]);
					}
				}
				var last_timespan = new Date();
				last_timespan.setDate(1);
				last_timespan.setMonth(last_timespan.getMonth()-1);
				var last_timespan_str = last_timespan.getFullYear() + "/" + pad(last_timespan.getMonth()+1, 2) + "/" + pad(last_timespan.getDate(), 2) + ' - ';
				var last_day = new Date(last_timespan.getFullYear(), last_timespan.getMonth() + 1, 0).getDate();
				last_timespan.setDate(last_day);
				last_timespan_str += last_timespan.getFullYear() + "/" + pad(last_timespan.getMonth()+1, 2) + "/" + pad(last_timespan.getDate(), 2);
				
				if ($scope.timespans.indexOf(last_timespan_str) < 0)
				{
					$scope.timespans.push(last_timespan_str);
				}
				var this_timespan = new Date();
				this_timespan.setDate(1);
				var this_timespan_str = this_timespan.getFullYear() + "/" + pad(this_timespan.getMonth()+1, 2) + "/" + pad(this_timespan.getDate(), 2) + ' - ';
				var this_day = new Date(this_timespan.getFullYear(), this_timespan.getMonth() + 1, 0).getDate();
				this_timespan.setDate(this_day);
				this_timespan_str += this_timespan.getFullYear() + "/" + pad(this_timespan.getMonth()+1, 2) + "/" + pad(this_timespan.getDate(), 2);
				if ($scope.timespans.indexOf(this_timespan_str) < 0)
				{
					$scope.timespans.push(this_timespan_str);
				}
				$scope.currenttimespan = $scope.timespans[$scope.timespans.length-1];
				$scope.computePayroll(false);
			}
		}
	}
    };
    $scope.addRow = function () {
	$scope.gridOptions.data.push({});
    }
    $scope.deleteRow = function () {
	var rows = $scope.gridApi.selection.getSelectedRows();
	for (var i = 0; i < $scope.gridOptions.data.length; i++)
	{
		if ($scope.gridOptions.data[i] == rows[0])
		{
			$scope.gridOptions.data.splice(i, 1);
			break;
		}
	}
    }
    $scope.finishEdit = function () {
    }
    $scope.editEmployeeInfo = function () {
	if ($scope.currentEdit == "employeeInfo")
	{
		$scope.finishEdit();
		$scope.hideGrid = true;
		$scope.currentEdit = null;
	}
	else
	{
		if ($scope.currentEdit)
		{
			$scope.finishEdit();
		}
		$scope.currentEdit = "employeeInfo";
		$scope.hideGrid = false;
		if (!$scope.payroll_data.employee_info)
		{
			$scope.payroll_data.employee_info = [];
		}
		$scope.gridOptions.columnDefs = [{field: 'id', displayName: 'ID'},
						 {field: 'name', displayName: 'Name'},
						 {field: 'rate', displayName: 'Rate', type: "number", cellFilter: "currency"},
						 {field: 'project_payable_cutoff', displayName: 'Project Payable Cutoff', type: "number"},
						 {field: 'monthly_benefits', displayName: 'Monthly Benefits', type: "number", cellFilter: "currency"},
						 {field: 'annual_vacation_days', displayName: 'Annual Vacation Days', type: "number"},
						 {field: 'active', displayName: 'Active', type: 'boolean'}
						];
		$scope.gridOptions.data = $scope.payroll_data.employee_info;
	}
    };
    $scope.editVacationHistory = function () {
	if ($scope.currentEdit == "vacationHistory")
	{
		$scope.finishEdit();
		$scope.hideGrid = true;
		$scope.currentEdit = null;
	}
	else
	{
		if ($scope.currentEdit)
		{
			$scope.finishEdit();
		}
		$scope.currentEdit = "vacationHistory";
		$scope.hideGrid = false;
		if (!$scope.payroll_data.vacation_history)
		{
			$scope.payroll_data.vacation_history = [];
		}
		$scope.gridOptions.columnDefs = [{field: 'id', displayName: 'Employee ID'},
						 {field: 'date', displayName: 'Date Earned', type: "date", cellFilter: 'date:"yyyy-MM-dd"'},
						 {field: 'days', displayName: 'Days', type: "number"}
						];
		$scope.gridOptions.data = $scope.payroll_data.vacation_history;
	}
    };
    $scope.editQuickbooksMapping = function () {
	if ($scope.currentEdit == "quickbooksMapping")
	{
		$scope.finishEdit();
		$scope.hideGrid = true;
		$scope.currentEdit = null;
	}
	else
	{
		if ($scope.currentEdit)
		{
			$scope.finishEdit();
		}
		$scope.currentEdit = "quickbooksMapping";
		$scope.hideGrid = false;
		if (!$scope.payroll_data.quickbooks_mapping)
		{
			$scope.payroll_data.quickbooks_mapping = [];
		}
		$scope.gridOptions.columnDefs = [{field: 'code', displayName: 'Billing Code'},
						 {field: 'internal', displayName: 'Internal Label'},
						 {field: 'quickbooks', displayName: 'Quickbooks Label'}
						];
		$scope.gridOptions.data = $scope.payroll_data.quickbooks_mapping;
	}
    };
    $scope.computePayroll = function (force) {
    	$http.get("report.php?period="+$scope.currenttimespan+"&output=json&report=Billing+Summary&grouping=Daily&merge=Split+Users&transpose=Off&taskfilter=")
    	    .success(function(data) {
    	      $scope.users = data;
    	});
    };
    
}]);
