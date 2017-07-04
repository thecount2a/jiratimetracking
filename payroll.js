function pad(n, width, z) {
  z = z || '0';
  n = n + '';
  return n.length >= width ? n : new Array(width - n.length + 1).join(z) + n;
}

var app = angular.module("payrollApp", ['ngTouch', 'ui.grid', 'ui.grid.edit', 'ui.grid.selection', 'ngCookies']);
app.controller("payrollController", ["$scope", "$http", "$cookies", "$window", function($scope, $http, $cookies, $window) {
    $scope.users = [];
    $scope.timespans = [];
    $scope.blob = null;
    $scope.blob_loaded = false;
    $scope.blob_nonce = null;
    $scope.payroll_data = {};
    $scope.payroll_key = "";
    $scope.payroll_decrypted = false;

    $scope.hideGrid = true;
    $scope.currentEdit = null;
    $scope.gridOptions = {};
    $scope.gridOptions.multiSelect = false;

    $window.onbeforeunload = function (event) {
	var key = sha256.pbkdf2(nacl.util.decodeUTF8($scope.payroll_key), nacl.util.decodeUTF8($cookies.get("timetracking_user")), 10000, 32);
	if ($scope.blob_nonce)
	{
		if(nacl.util.encodeBase64($scope.blob_nonce) + ':' + nacl.util.encodeBase64(nacl.secretbox(nacl.util.decodeUTF8(JSON.stringify($scope.payroll_data)), $scope.blob_nonce, key)) != $scope.blob)
		{
		    return "Are you sure you want to leave with unsaved changes?";
		}
	}
	return;
    }
    $scope.$on('$destroy', function(e) {
	$window.onbeforeunload = undefined;
    });

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
    $scope.Math = window.Math;
    $scope.currenttimespan = $scope.timespans[0];
    $scope.beginningoftime = new Date(0);
    $scope.currenttimespanstart = null;
    $scope.currenttimespanend = null
    $scope.savePayroll = function () {
	var key = sha256.pbkdf2(nacl.util.decodeUTF8($scope.payroll_key), nacl.util.decodeUTF8($cookies.get("timetracking_user")), 10000, 32);
	// Only save and generate new nonce if data changes
	if (!$scope.blob || (nacl.util.encodeBase64($scope.blob_nonce) + ':' + nacl.util.encodeBase64(nacl.secretbox(nacl.util.decodeUTF8(JSON.stringify($scope.payroll_data)), $scope.blob_nonce, key)) != $scope.blob))
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
	var rows = $scope.gridApi.selection.getSelectedRows();
	for (var i = 0; i < $scope.gridOptions.data.length; i++)
	{
		if ($scope.gridOptions.data[i] == rows[0])
		{
			$scope.gridOptions.data.splice(i, 0, {});
			return;
		}
	}
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
    $scope.allitemszero = function (items) {
	for (var i = 1; i < items.length; i++)
	{
		if (items[i] != "0")
		{
			return false;
		}
	}
	return true;
    }
    $scope.lookupCode = function (code, cutoff) {
	if (code=="1999") { return "Vacation"; }
	else if (code=="0099") { return "UP Vac"; }
	else if (Number(code) >= cutoff) { return "Paid"; }
	else { return "Unpaid"; }
    }
    $scope.laborHours = function () {
	return 176.0;
    }
    $scope.payableLaborHours = function (empId, cutoff) {
	var hours = 0.0;
	for (var col in $scope.users[empId][$scope.users[empId].length-1])
	{
		if (col != 0 && col != $scope.users[empId][$scope.users[empId].length-1].length -1)
		{
			if ($scope.lookupCode($scope.users[empId][0][col].split('_')[1], cutoff) == "Paid")
			{
				hours += Number($scope.users[empId][$scope.users[empId].length-1][col]);
			}
		}
	}
	return hours;
    }
    $scope.loggedUnpaidVac = function (empId, cutoff) {
	var logged = 0.0;
	for (var col in $scope.users[empId][$scope.users[empId].length-1])
	{
		if (col != 0 && col != $scope.users[empId][$scope.users[empId].length-1].length -1)
		{
			if ($scope.lookupCode($scope.users[empId][0][col].split('_')[1], cutoff) == "UP Vac")
			{
				logged += Number($scope.users[empId][$scope.users[empId].length-1][col]);
			}
		}
	}
	return logged;
    }
    $scope.vacationTally = function (empId, fromDate, toDate, selectSign = -1) {
	var tally = 0.0;
	for (var i = 0; i < $scope.payroll_data.vacation_history.length; i++)
	{
		var thisDate = new Date($scope.payroll_data.vacation_history[i].date);
		if (empId == $scope.payroll_data.vacation_history[i].id && thisDate >= fromDate && thisDate <= toDate)
		{
			if (selectSign < 0 || (selectSign == 1 && $scope.payroll_data.vacation_history[i].hours > 0) || (selectSign == 0 && $scope.payroll_data.vacation_history[i].hours < 0))
			{
				tally += $scope.payroll_data.vacation_history[i].hours;
			}
		}
	}
	return tally;
    }
    $scope.editEmployeeInfo = function () {
	if ($scope.currentEdit == "employeeInfo")
	{
		$scope.hideGrid = true;
		$scope.currentEdit = null;
	}
	else
	{
		$scope.currentEdit = "employeeInfo";
		$scope.hideGrid = false;
		if (!$scope.payroll_data.employee_info)
		{
			$scope.payroll_data.employee_info = [];
		}
		$scope.gridOptions.columnDefs = [{field: 'id', displayName: 'ID'},
						 {field: 'name', displayName: 'Name'},
						 {field: 'quickbooksname', displayName: 'Quickbooks Name'},
						 {field: 'quickbooksitem', displayName: 'Quickbooks ITEM'},
						 {field: 'quickbookspitem', displayName: 'Quickbooks PITEM'},
						 {field: 'rate', displayName: 'Per Period Rate', type: "number", cellFilter: "currency"},
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
		$scope.hideGrid = true;
		$scope.currentEdit = null;
	}
	else
	{
		$scope.currentEdit = "vacationHistory";
		$scope.hideGrid = false;
		if (!$scope.payroll_data.vacation_history)
		{
			$scope.payroll_data.vacation_history = [];
		}
		$scope.gridOptions.columnDefs = [{field: 'id', displayName: 'Employee ID'},
						 {field: 'date', displayName: 'Date Earned', type: "date", cellFilter: 'date:"yyyy-MM-dd"'},
						 {field: 'hours', displayName: 'Hours', type: "number"},
						 {field: 'auto', displayName: 'Auto Generated', enableCellEdit: false, width: "10%", type: "boolean"},
						 {field: 'comment', displayName: 'Comment'}
						];
		$scope.gridOptions.data = $scope.payroll_data.vacation_history;
	}
    };
    $scope.editQuickbooksMapping = function () {
	if ($scope.currentEdit == "quickbooksMapping")
	{
		$scope.hideGrid = true;
		$scope.currentEdit = null;
	}
	else
	{
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
    $scope.editRawData = function () {
	$scope.hideGrid = true;
	$scope.currentEdit = null;
	var newData = prompt(JSON.stringify($scope.payroll_data));
	if (newData)
	{
		$scope.payroll_data = JSON.parse(newData);
	}
    }
    $scope.computePayroll = function (force) {
	var parts = $scope.currenttimespan.split(' - ');
	$scope.hideGrid = true;
	$scope.currentEdit = null;
	$scope.currenttimespanstart = new Date(parts[0]);
	$scope.currenttimespanend = new Date(parts[1]);
    	$http.get("report.php?period="+$scope.currenttimespan+"&output=json&report=Billing+Summary&grouping=Daily&merge=Split+Users&transpose=On&taskfilter=")
    	    .success(function(data) {
    	      	$scope.users = data;
	      	if (!$scope.payroll_data.timespans)
	      	{
			$scope.payroll_data.timespans = [];
	      	}
	      	if (new Date() > $scope.currenttimespanend && ($scope.payroll_data.timespans.indexOf($scope.currenttimespan) < 0 || force))
	      	{
			var new_vacation = [];
			// Get rid of already computed vacation numbers for this period
			for (var i = 0; i < $scope.payroll_data.vacation_history.length; i++)
			{
				var thisDate = new Date($scope.payroll_data.vacation_history[i].date);
				if (!$scope.payroll_data.vacation_history[i].auto || (thisDate < $scope.currenttimespanstart || thisDate > $scope.currenttimespanend))
				{
					new_vacation.push($scope.payroll_data.vacation_history[i]);
				}
			}
			$scope.payroll_data.vacation_history = new_vacation;
			for (var idx in $scope.payroll_data.employee_info)
			{
				if (data[$scope.payroll_data.employee_info[idx].id])
				{
					var empId = $scope.payroll_data.employee_info[idx].id;
					var empPayableCutoff = $scope.payroll_data.employee_info[idx].project_payable_cutoff;
					var earned_rate = $scope.payroll_data.employee_info[idx].annual_vacation_days / (365 * 5 / 7 - $scope.payroll_data.employee_info[idx].annual_vacation_days);
					var earned_hours = Math.min($scope.laborHours() - $scope.loggedUnpaidVac(empId), $scope.payableLaborHours(empId, empPayableCutoff)) * earned_rate;
					$scope.payroll_data.vacation_history.push({id: empId, date: $scope.currenttimespanend, hours: earned_hours, comment: "Earned vacation", auto: true});
					var hours_short = Math.max(Math.max($scope.laborHours() - $scope.payableLaborHours(empId, empPayableCutoff), 0)-$scope.loggedUnpaidVac(empId), 0);
					var unlogged_vacation_hours = Math.min(hours_short, $scope.vacationTally(empId, $scope.beginningoftime, $scope.currenttimespanend));
					if (unlogged_vacation_hours > 0.00000001)
					{
						$scope.payroll_data.vacation_history.push({id: empId, date: $scope.currenttimespanend, hours: -unlogged_vacation_hours, comment: "Vacation spent", auto: true});
					}
				}
				else
				{
					alert("Missing data in report for user " + $scope.payroll_data.employee_info[idx].id);
				}
			}
			if ($scope.payroll_data.timespans.indexOf($scope.currenttimespan) < 0)
			{
				$scope.payroll_data.timespans.push($scope.currenttimespan);
			}
		}	
    	});
    };
    $scope.generateQuickbooks = function () {
	var code_to_quickbooks = {};
	for (var mapping in $scope.payroll_data.quickbooks_mapping)
	{
		code_to_quickbooks[$scope.payroll_data.quickbooks_mapping[mapping].code] = $scope.payroll_data.quickbooks_mapping[mapping].quickbooks;
	}
	var quickbooksText = '';
	for (var idx in $scope.payroll_data.employee_info)
	{
		var empId = $scope.payroll_data.employee_info[idx].id;
		var aggregate = {};
		if ($scope.users[empId])
		{
			for (var col in $scope.users[empId][0])
			{
				if (col > 0 && col < $scope.users[empId][0].length - 1)
				{
					var code = $scope.users[empId][0][col].split('_')[$scope.users[empId][0][col].split('_').length-1];
					if (code_to_quickbooks[code])
					{
						if (aggregate[code_to_quickbooks[code]])
						{
							aggregate[code_to_quickbooks[code]] += Number($scope.users[empId][$scope.users[empId].length-1][col]);
						}
						else
						{
							aggregate[code_to_quickbooks[code]] = Number($scope.users[empId][$scope.users[empId].length-1][col]);
						}
					}
					else
					{
						alert("Code not found in quickbooks mapping: " + code);
						return;
					}
				}
			}
		}
		else
		{
			alert("Missing data in report for user " + $scope.payroll_data.employee_info[idx].id);
			return;
		}
		for (var quickbooks in aggregate)
		{
			quickbooksText += 'TIMEACT\t'+date+'\t';
		}
	}
    }
    
}]);
